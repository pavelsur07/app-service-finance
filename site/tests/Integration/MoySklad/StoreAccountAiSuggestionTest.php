<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\Action\AcceptStoreAccountSuggestionsAction;
use App\MoySklad\Application\Action\RequestStoreAccountSuggestionsAction;
use App\MoySklad\Application\Service\StoreAccountSuggestionConsumer;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Domain\StoreAccountRulePolicy;
use App\MoySklad\Infrastructure\Query\StoreAccountSuggestionStore;
use App\MoySklad\Infrastructure\Query\StoreBalanceQuery;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\ServiceGateway\FakeAiTasks;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

/** ИИ для складов, которые не разобрали правила: постановка, ответ, показ и «Принять все» (plan.md §4, этап 2). */
final class StoreAccountAiSuggestionTest extends IntegrationTestCase
{
    private const COMPANY = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private const CONNECTION = MoySkladConnectionBuilder::DEFAULT_ID;
    private FakeAiTasks $ai;
    private MockClock $clock;
    private string $owner;
    /** @var array<string, string> название → external id */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        foreach ([$owner, CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($owner)->build(), MoySkladConnectionBuilder::aConnection()->build()] as $entity) {
            $this->em->persist($entity);
        }
        foreach (['ФФ К2 (РнД)', 'FBS К2 (РНД)', 'Брак', 'Газелька'] as $i => $name) {
            $store = MoySkladStoreBuilder::aStore()->withIndex($i + 1)->withName($name)->build();
            $this->em->persist($store);
            $this->stores[$name] = $store->getExternalId();
        }
        $this->em->flush();
        $this->owner = (string) $owner->getId();
        self::assertTrue(self::getContainer()->get(SeedBalanceStructureAction::class)(self::COMPANY));
        self::getContainer()->get(BalanceFacade::class)->ensureStandardChart(self::COMPANY);
        $this->connection->executeStatement('INSERT INTO moysklad_store_balance_links (company_id, connection_id, store_external_id, balance_account_id, excluded, actor_user_id, updated_at) VALUES (?, ?, ?, ?, false, ?, now())',
            [self::COMPANY, self::CONNECTION, $this->stores['ФФ К2 (РнД)'], $this->account('ACC_INV_FF'), $this->owner]);
        $this->ai = new FakeAiTasks();
        $this->clock = new MockClock('2026-11-27 10:00:00');
    }

    public function testRequestsOnlyStoresWithoutRuleOnceWithCompanyExamples(): void
    {
        self::assertSame(2, $this->request());
        self::assertSame(0, $this->request(), 'Ответ ещё не пришёл — повторно не ставится.');

        $sent = array_column($this->ai->requests, null, 'key');
        self::assertCount(2, $sent);
        $fbs = array_values(array_filter($this->ai->requests, fn (array $r): bool => 'FBS К2 (РНД)' === $r['payload']['store']['name']))[0];
        self::assertSame([RequestStoreAccountSuggestionsAction::TASK_TYPE, self::COMPANY], [$fbs['taskType'], $fbs['companyId']]);
        self::assertSame([['name' => 'ФФ К2 (РнД)', 'account_code' => 'ACC_INV_FF', 'excluded' => false]], $fbs['payload']['examples']);
        $codes = array_column($fbs['payload']['accounts'], 'code');
        self::assertContains('ACC_INV_FF', $codes);
        self::assertNotContains('ACC_INV_ADJUSTMENT', $codes, 'Только счета актива.');
    }

    public function testConfidentAiSuggestionIsAcceptedTogetherWithRulesAndUnsureIsNot(): void
    {
        $this->request();
        $this->answer('FBS К2 (РНД)', AiResultStatus::ACCEPTED, ['account_code' => 'ACC_INV_FF', 'excluded' => false, 'reason' => 'тот же оператор К2', 'confidence' => 0.9]);
        $this->answer('Газелька', AiResultStatus::NEEDS_REVIEW, ['account_code' => 'ACC_INV_WAREHOUSE', 'excluded' => false, 'reason' => 'похоже на машину', 'confidence' => 0.6]);

        $shown = $this->suggestions();
        self::assertSame(['ai', true, 'тот же оператор К2'], [$shown[$this->stores['FBS К2 (РНД)']]->source, $shown[$this->stores['FBS К2 (РНД)']]->confident, $shown[$this->stores['FBS К2 (РНД)']]->reason]);
        self::assertFalse($shown[$this->stores['Газелька']]->confident);

        $result = (self::getContainer()->get(AcceptStoreAccountSuggestionsAction::class))(self::COMPANY, self::CONNECTION, $this->owner);
        self::assertSame(['accepted' => 2, 'failed' => 0], $result, 'Брак по правилу и FBS по уверенному ИИ.');
        self::assertSame($this->account('ACC_INV_FF'), $this->connection->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$this->stores['FBS К2 (РНД)']]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM moysklad_store_balance_links WHERE store_external_id = ?', [$this->stores['Газелька']]));
    }

    public function testRenamedStoreHidesOldAnswerAndIsAskedAgain(): void
    {
        $this->request();
        $this->answer('Газелька', AiResultStatus::ACCEPTED, ['account_code' => 'ACC_INV_WAREHOUSE', 'excluded' => false, 'reason' => 'машина компании', 'confidence' => 0.9]);
        $this->connection->executeStatement("UPDATE moysklad_stores SET name = 'Иванов' WHERE external_id = ?", [$this->stores['Газелька']]);

        self::assertArrayNotHasKey($this->stores['Газелька'], $this->suggestions());
        self::assertSame(1, $this->request());
    }

    public function testDontKnowRejectedAndForeignTaskGiveNoSuggestion(): void
    {
        $this->request();
        $this->answer('Газелька', AiResultStatus::NEEDS_REVIEW, ['account_code' => null, 'excluded' => false, 'reason' => 'не понять', 'confidence' => 0.0]);
        $this->answer('FBS К2 (РНД)', AiResultStatus::REJECTED, null);
        $this->consumer()->consume($this->aiResult(Uuid::uuid4()->toString(), AiResultStatus::ACCEPTED, ['account_code' => 'ACC_INV_FF', 'excluded' => false, 'reason' => 'x', 'confidence' => 0.9]));

        $shown = $this->suggestions();
        self::assertArrayNotHasKey($this->stores['Газелька'], $shown);
        self::assertArrayNotHasKey($this->stores['FBS К2 (РНД)'], $shown);
    }

    public function testSwitchedOffOrFailingServiceLeavesNothingAndIsRetried(): void
    {
        $this->ai->enabled = false;
        self::assertSame(0, $this->request());
        $this->ai->enabled = true;
        $this->ai->fail = true;
        self::assertSame(0, $this->request());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_store_account_suggestions'));

        $this->ai->fail = false;
        self::assertSame(2, $this->request());
        $this->clock->modify('+2 hours');
        self::assertSame(2, $this->request(), 'Зависший запрос старше часа ставится снова.');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_store_account_suggestions'));
        self::assertCount(2, array_unique(array_column($this->ai->requests, 'key')), 'Payload не менялся — тот же ключ, сервис вернёт ту же задачу.');
        $this->clock->modify('+2 hours');
        self::assertSame(2, $this->request(), 'Третья отправка.');
        $this->clock->modify('+2 hours');
        self::assertSame(0, $this->request(), 'Отказ при отправке (неизвестная задача у сервиса) не повторяется бесконечно: три отправки на название.');
    }

    public function testFailedAnswerIsAskedAgainWithNewKeyUpToThreeAttempts(): void
    {
        $this->request();
        for ($attempt = 2; $attempt <= 3; ++$attempt) {
            $this->answer('Газелька', AiResultStatus::FAILED, null);
            self::assertSame(1, $this->request(), 'Технический сбой — повтор, попытка '.$attempt);
        }
        $keys = array_column(array_filter($this->ai->requests, static fn (array $r): bool => 'Газелька' === $r['payload']['store']['name']), 'key');
        self::assertCount(3, array_unique($keys), 'Новая попытка — новый ключ, иначе сервис вернёт ту же упавшую задачу.');
        $this->answer('Газелька', AiResultStatus::FAILED, null);
        self::assertSame(0, $this->request(), 'После трёх попыток — до переименования или нового промпта.');
    }

    public function testStaleRetryWithChangedPayloadUsesNewKey(): void
    {
        $this->request();
        $this->connection->executeStatement('INSERT INTO moysklad_store_balance_links (company_id, connection_id, store_external_id, balance_account_id, excluded, actor_user_id, updated_at) VALUES (?, ?, ?, NULL, true, ?, now())',
            [self::COMPANY, self::CONNECTION, $this->stores['Брак'], $this->owner]);
        $this->clock->modify('+2 hours');

        self::assertSame(2, $this->request());
        self::assertCount(4, array_unique(array_column($this->ai->requests, 'key')), 'Примеры изменились — иначе сервис ответит idempotency_conflict.');
    }

    public function testRejectedAnswerIsFinal(): void
    {
        $this->request();
        $this->answer('Газелька', AiResultStatus::REJECTED, null);
        self::assertSame(0, $this->request());
    }

    public function testLateRetryDoesNotReopenCompletedAnswer(): void
    {
        $this->request();
        $this->answer('Газелька', AiResultStatus::ACCEPTED, ['account_code' => 'ACC_INV_WAREHOUSE', 'excluded' => false, 'reason' => 'машина', 'confidence' => 0.9]);
        $store = self::getContainer()->get(StoreAccountSuggestionStore::class);
        $request = array_values(array_filter($this->ai->requests, static fn (array $r): bool => 'Газелька' === $r['payload']['store']['name']))[0];
        $row = $store->requests(self::COMPANY, self::CONNECTION)[$this->stores['Газелька']];
        // прогон прочитал строку как зависшую до ответа и пишет её снова уже после ответа
        $store->markRequested(self::COMPANY, self::CONNECTION, $this->stores['Газелька'], $row['fingerprint'], $row['promptVersion'], $request['taskId'], 1, $row['seq'], $row['payloadHash'], new \DateTimeImmutable());

        self::assertArrayHasKey($this->stores['Газелька'], $this->suggestions());
    }

    public function testExhaustedBudgetRequestsNothing(): void
    {
        $this->ai->budgetExceeded = true;
        self::assertSame(0, $this->request());
    }

    private function request(): int
    {
        $c = self::getContainer();

        return (new RequestStoreAccountSuggestionsAction($c->get(StoreBalanceQuery::class), $c->get(BalanceFacade::class), new StoreAccountRulePolicy(),
            $c->get(StoreAccountSuggestionStore::class), $this->ai, $this->clock, new NullLogger()))(self::COMPANY, self::CONNECTION);
    }

    /** @param array<string, mixed>|null $output */
    private function answer(string $store, AiResultStatus $status, ?array $output): void
    {
        $request = array_values(array_filter($this->ai->requests, static fn (array $r): bool => $store === $r['payload']['store']['name']));
        $last = array_pop($request);
        self::assertNotNull($last, 'Задача для склада не ставилась.');
        $this->consumer()->consume($this->aiResult($last['taskId'], $status, $output));
    }

    /** @param array<string, mixed>|null $output */
    private function aiResult(string $taskId, AiResultStatus $status, ?array $output): AiTaskResult
    {
        return new AiTaskResult($taskId, RequestStoreAccountSuggestionsAction::TASK_TYPE, self::COMPANY, $status, $output, [], $output['confidence'] ?? null,
            'trace', ['deepseek/deepseek-flash'], 'v1', 10, 5, null, new \DateTimeImmutable('2026-11-27 10:01:00'));
    }

    private function consumer(): StoreAccountSuggestionConsumer
    {
        return self::getContainer()->get(StoreAccountSuggestionConsumer::class);
    }

    /** @return array<string, \App\MoySklad\Application\DTO\ResolvedStoreSuggestion> */
    private function suggestions(): array
    {
        $c = self::getContainer();

        return $c->get(StoreAccountSuggestions::class)->forStores(
            $c->get(StoreBalanceQuery::class)->stores(self::COMPANY, self::CONNECTION, null),
            $c->get(BalanceFacade::class)->getPostingAccounts(self::COMPANY),
            $c->get(StoreAccountSuggestionStore::class)->answers(self::COMPANY, self::CONNECTION),
        );
    }

    private function account(string $code): string
    {
        return (string) $this->connection->fetchOne('SELECT id FROM balance_accounts WHERE company_id = ? AND code = ?', [self::COMPANY, $code]);
    }
}
