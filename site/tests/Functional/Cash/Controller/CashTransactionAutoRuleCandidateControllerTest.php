<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\AutoRule\RequestCashRulePhraseAction;
use App\Cash\Application\Service\AutoRule\CashRulePhraseResultConsumer;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleCandidateControllerTest extends WebTestCaseBase
{
    public function testReportIsAuthenticatedCompanyScopedAndReadOnly(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cash-transaction-auto-rules/candidates');
        self::assertResponseRedirects();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(1)
            ->withCompany($company)
            ->withName('Комиссия банку')
            ->build();
        $otherCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(2)
            ->withCompany($company)
            ->withName('Аренда')
            ->build();

        $eligibleAccount = $this->account($company, 1, 'Основной счёт');
        $conflictingAccount = $this->account($company, 2, 'Счёт с конфликтом');
        $autoChangedAccount = $this->account($company, 3, 'Счёт после автоправила');
        $sameDayAccount = $this->account($company, 4, 'Счёт одного дня');
        $nestedAuditAccount = $this->account($company, 6, 'Счёт вложенного аудита');

        $otherUser = UserBuilder::aUser()->withIndex(2)->asCompanyOwner()->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherUser)->build();
        $otherCompanyCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(3)
            ->withCompany($otherCompany)
            ->withName('Чужая статья')
            ->build();
        $otherCompanyAccount = $this->account($otherCompany, 5, 'Чужой счёт');

        foreach ([
            $user,
            $company,
            $category,
            $otherCategory,
            $eligibleAccount,
            $conflictingAccount,
            $autoChangedAccount,
            $sameDayAccount,
            $nestedAuditAccount,
            $otherUser,
            $otherCompany,
            $otherCompanyCategory,
            $otherCompanyAccount,
        ] as $entity) {
            $this->em()->persist($entity);
        }

        $today = new \DateTimeImmutable('today');
        $eligible = $this->transactions($company, $eligibleAccount, array_fill(0, 5, $category), 'eligible', $today);
        $blankSource = $this->transactions($company, $eligibleAccount, array_fill(0, 5, $category), '   ', $today);
        $conflicting = $this->transactions(
            $company,
            $conflictingAccount,
            [$category, $category, $category, $category, $otherCategory],
            'conflict',
            $today,
        );
        $autoChanged = $this->transactions($company, $autoChangedAccount, array_fill(0, 5, $category), 'auto', $today);
        $sameDay = $this->transactions(
            $company,
            $sameDayAccount,
            array_fill(0, 5, $category),
            'same-day',
            $today,
            sameDay: true,
        );
        $nestedAudit = $this->transactions(
            $company,
            $nestedAuditAccount,
            array_fill(0, 5, $category),
            'nested',
            $today,
        );
        $foreign = $this->transactions(
            $otherCompany,
            $otherCompanyAccount,
            array_fill(0, 5, $otherCompanyCategory),
            'foreign',
            $today,
        );

        foreach (array_merge($eligible, $blankSource, $conflicting, $autoChanged, $sameDay, $nestedAudit, $foreign) as $transaction) {
            $this->em()->persist($transaction);
        }
        $this->em()->flush();

        $manualAuditAt = new \DateTimeImmutable('-2 hours');
        foreach ([
            [$eligible, $user],
            [$blankSource, $user],
            [$conflicting, $user],
            [$autoChanged, $user],
            [$sameDay, $user],
            [$foreign, $otherUser],
        ] as [$transactions, $actor]) {
            foreach ($transactions as $transaction) {
                $this->em()->persist($this->manualCategoryAudit($transaction, $actor, $manualAuditAt));
            }
        }
        foreach ($nestedAudit as $transaction) {
            $this->em()->persist($this->manualCategoryAudit($transaction, $user, $manualAuditAt, nested: true));
        }
        $this->em()->flush();

        $this->em()->persist(new AuditLog(
            (string) $company->getId(),
            CashTransaction::class,
            (string) $autoChanged[0]->getId(),
            AuditLogAction::UPDATE,
            [
                'correlationId' => Uuid::uuid7()->toString(),
                'autoRules' => ['cashflowCategory' => ['id' => Uuid::uuid4()->toString(), 'revision' => 1]],
                'changes' => ['cashflowCategory' => ['before' => $category->getId(), 'after' => $category->getId()]],
            ],
            $user->getId(),
            new \DateTimeImmutable('-1 hour'),
        ));
        $this->em()->flush();

        $auditCountBefore = $this->em()->getRepository(AuditLog::class)->count([]);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cash-transaction-auto-rules/candidates');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auto-rule-candidates[data-candidate-count="4"]');
        self::assertSelectorCount(4, '.auto-rule-candidate');
        self::assertSelectorTextContains('body', 'Основной счёт');
        self::assertSelectorTextContains('body', 'eligible');
        self::assertSelectorTextContains('body', 'Счёт вложенного аудита');
        self::assertSelectorTextContains('body', 'nested');
        self::assertSelectorTextContains('body', 'Комиссия банку');
        self::assertSelectorTextContains('body', '5 операций / 5 дат');
        self::assertSelectorTextContains('body', '10 операций / 5 дат');
        self::assertSelectorTextNotContains('body', 'Счёт с конфликтом');
        self::assertSelectorTextNotContains('body', 'Счёт после автоправила');
        self::assertSelectorTextNotContains('body', 'Счёт одного дня');
        self::assertSelectorTextNotContains('body', 'Чужой счёт');
        self::assertSelectorTextNotContains('body', 'Чужая статья');
        self::assertSame($auditCountBefore, $this->em()->getRepository(AuditLog::class)->count([]));
        $accountRow = $crawler->filter('.auto-rule-candidate')->reduce(static fn ($row) => str_contains($row->text(), 'Основной счёт'));
        self::assertCount(5, $accountRow->filter('a.operation-example'));
        $client->click($accountRow->selectLink('Подготовить правило')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name$="[field]"] option[value="MONEY_ACCOUNT"][selected]');
        self::assertSelectorExists('select[name$="[moneyAccount]"] option[value="'.$eligibleAccount->getId().'"][selected]');
    }

    public function testAiProposalOpensPrefilledRuleForm(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->withName('Аренда офиса')->build();
        $account = $this->account($company, 1, 'Основной счёт');
        $landlord = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('ООО Арендодатель')->build();
        foreach ([$user, $company, $category, $account, $landlord] as $entity) {
            $this->em()->persist($entity);
        }
        $transactions = $this->transactions($company, $account, array_fill(0, 3, $category), 'bank', new \DateTimeImmutable('today'));
        foreach ($transactions as $transaction) {
            $transaction->setCounterparty($landlord);
            $this->em()->persist($transaction);
        }
        $this->em()->flush();
        foreach ($transactions as $transaction) {
            $this->em()->getConnection()->insert('cash_category_suggestion', [
                'id' => Uuid::uuid7()->toString(),
                'company_id' => (string) $company->getId(),
                'transaction_id' => (string) $transaction->getId(),
                'task_id' => Uuid::uuid4()->toString(),
                'status' => 'accepted',
                'category_id' => (string) $category->getId(),
                'confidence' => '0.9500',
                'verdict' => 'accepted',
                'requested_at' => '2026-10-01 12:00:00',
            ]);
        }

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cash-transaction-auto-rules/candidates');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auto-rule-ai-proposals[data-proposal-count="1"]');
        self::assertSelectorTextContains('.auto-rule-ai-proposal', 'ООО Арендодатель');
        self::assertSelectorTextContains('.auto-rule-ai-proposal', 'Аренда офиса');
        self::assertSelectorTextContains('.auto-rule-ai-proposal', '3 операций');
        $link = $crawler->filter('.auto-rule-ai-proposal a')->link();
        // Образец — последняя по дате подтверждённая операция.
        self::assertStringContainsString('fromTransaction='.$transactions[0]->getId(), $link->getUri());

        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="ООО Арендодатель → Аренда офиса"]');
    }

    public function testPhraseSearchChecksCompanyBudgetAndValidatesOtherHumanCategoriesBeforePrefill(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->withName('Лицензия')->build();
        $other = CashflowCategoryBuilder::aCashflowCategory()->withIndex(2)->withCompany($company)->withName('Внедрение')->build();
        $historic = CashflowCategoryBuilder::aCashflowCategory()->withIndex(3)->withCompany($company)->withName('Архивная покупка')->build();
        $account = $this->account($company, 1, 'Основной');
        $party = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->withName('Тестовый поставщик')->build();
        foreach ([$user, $company, $target, $other, $historic, $account, $party] as $entity) {
            $this->em()->persist($entity);
        }
        $today = new \DateTimeImmutable('today');
        $ops = $this->transactions($company, $account, [$target, $target, $other, $other], 'bank', $today);
        $ops[3]->setOccurredAt($today->modify('-7 days'));
        $descriptions = ['Лицензии по счёту №123 Фамилия', 'Лицензии по счёту №124', 'Лицензии и внедрение', 'Внедрение'];
        foreach ($ops as $i => $op) {
            $op->setCounterparty($party)->setDescription($descriptions[$i]);
            $this->em()->persist($op);
            $this->em()->persist($this->manualCategoryAudit($op, $user, $today));
        }
        // Пять платежей за пределами окна выгрузки составляют обычный исторический кандидат.
        $old = $this->transactions($company, $account, array_fill(0, 5, $historic), 'historic-source', $today->modify('-40 days'));
        foreach ($old as $op) {
            $op->setCounterparty($party)->setDescription('Обслуживание по договору');
            $this->em()->persist($op);
            $this->em()->persist($this->manualCategoryAudit($op, $user, $today));
        }
        $this->em()->flush();

        $taskId = Uuid::uuid7()->toString();
        $requests = new class {
            public bool $exhausted = false;
            public ?string $overlapStatus = null;
            /** @var list<string> */
            public array $keys = [];

            public function sent(): int
            {
                return \count($this->keys);
            }
        };
        $gateway = $this->createMock(AiTaskFacadeInterface::class);
        $gateway->method('isEnabledForCompany')->willReturn(true);
        $gateway->method('budgetFor')->willReturnCallback(static function () use ($requests): AiCompanyBudget {
            return new AiCompanyBudget(100, $requests->exhausted ? 100 : 0, 1000, 10, 7);
        });
        $retryTask = Uuid::uuid7()->toString();
        $gateway->method('taskIdFor')->willReturnCallback(static fn ($companyId, $type, $key) => str_ends_with($key, ':2') ? $retryTask : $taskId);
        $gateway->expects(self::exactly(6))->method('requestTask')->willReturnCallback(static function ($companyId, $type, $payload, $key, $actor) use ($taskId, $retryTask, $company, $user, $requests, $party, $target, $ops): ?string {
            self::assertSame($company->getId(), $companyId);
            self::assertSame($user->getId(), $actor);
            self::assertSame('cash_rule_phrase', $type);
            self::assertCount(4, $payload['examples']);
            self::assertCount(2, array_filter(array_column($payload['examples'], 'target')));
            $text = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('№123', $text);
            self::assertStringNotContainsString('фамилия', $text);
            self::assertStringNotContainsString('поставщик', $text);
            self::assertStringContainsString('лицензии', $text);
            self::assertStringNotContainsString('обслуживание', $text);

            $requests->keys[] = $key;
            if (1 === $requests->sent()) {
                $action = self::getContainer()->get(RequestCashRulePhraseAction::class);
                $requests->overlapStatus = $action((string) $companyId, (string) $actor, $party->getId(), $ops[0]->getDirection()->value, (string) $target->getId());
            }

            return match (\count($requests->keys)) {
                1 => throw new \RuntimeException('synthetic transport failure'),
                2 => null,
                6 => $retryTask,
                default => $taskId,
            };
        });
        static::getContainer()->set(AiTaskFacadeInterface::class, $gateway);
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $path = '/cash-transaction-auto-rules/candidates';
        $page = $client->request('GET', $path);
        self::assertSelectorTextContains('.auto-rule-candidates', 'historic-source');
        self::assertSelectorTextNotContains('#phrase-target', 'Архивная покупка');
        $option = $party->getId().'/'.$ops[0]->getDirection()->value.'/'.$target->getId();
        $form = $page->selectButton('Найти повторяющуюся фразу с ИИ')->form(['target' => $option]);
        $values = $form->getPhpValues();
        $client->request('POST', $path.'/phrases', ['target' => $option, '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $requests->exhausted = true;
        $client->request('POST', $path.'/phrases', $values);
        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_rule_phrase_search WHERE company_id = ?', [$company->getId()]));
        $requests->exhausted = false;
        $client->request('POST', $path.'/phrases', $values);
        self::assertResponseRedirects();

        $db = $this->em()->getConnection();
        $status = static fn () => $db->fetchOne('SELECT status FROM cash_rule_phrase_search WHERE company_id = ?', [$company->getId()]);
        self::assertSame('sending', $requests->overlapStatus);
        self::assertSame(1, $requests->sent());
        self::assertSame('retryable', $status());
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame('retryable', $status());
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame('pending', $status());
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame(3, $requests->sent());
        $db->executeStatement("UPDATE cash_rule_phrase_search SET requested_at = CURRENT_TIMESTAMP - INTERVAL '2 hours' WHERE company_id = ?", [$company->getId()]);
        $client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Ответ задерживается');
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame(4, $requests->sent());
        $db->executeStatement("UPDATE cash_rule_phrase_search SET status = 'sending', requested_at = CURRENT_TIMESTAMP - INTERVAL '2 hours' WHERE company_id = ?", [$company->getId()]);
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame('pending', $status());
        self::assertSame(5, $requests->sent());
        self::assertCount(1, array_unique($requests->keys));

        $consumer = static::getContainer()->get(CashRulePhraseResultConsumer::class);
        $failure = new AiTaskResult($taskId, 'cash_rule_phrase', (string) $company->getId(), AiResultStatus::FAILED,
            null, [], null, $taskId, [], null, 0, 0, 'llm_unavailable', $today);
        $consumer->consume($failure);
        self::assertSame('failed', $status());
        $client->request('POST', $path.'/phrases', $values);
        self::assertSame('pending', $status());
        self::assertNotSame($requests->keys[0], $requests->keys[5]);
        $consumer->consume($failure);
        self::assertSame('pending', $status());
        $result = new AiTaskResult($retryTask, 'cash_rule_phrase', (string) $company->getId(), AiResultStatus::NEEDS_REVIEW,
            ['phrase' => 'лицензии'], [], null, $taskId, [], 'cash_rule_phrase/v1', 0, 0, null, $today);
        $consumer->consume($result);
        $consumer->consume($result);
        $client->request('GET', $path.'?search='.Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        $page = $client->request('GET', $path.'?search='.$retryTask);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#phrase-review', 'Выбрана предлагаемая статья — 2');
        self::assertSelectorTextContains('#phrase-review', 'Человек выбрал другую статью — 1');
        self::assertSelectorTextContains('#phrase-review', 'Лицензии и внедрение');
        $client->click($page->selectLink('Подготовить правило по фразе')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="лицензии"]');
        self::assertSelectorExists('select[name$="[field]"] option[value="COUNTERPARTY"][selected]');
    }

    public function testExportHistoryLimitsBothGroupsAndKeepsFullLocalHistory(): void
    {
        static::createClient();
        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->build();
        $other = CashflowCategoryBuilder::aCashflowCategory()->withIndex(2)->withCompany($company)->build();
        $account = $this->account($company, 1, 'Основной');
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        foreach ([$user, $company, $target, $other, $account, $party] as $entity) {
            $this->em()->persist($entity);
        }
        $today = new \DateTimeImmutable('today');
        $rows = $this->transactions($company, $account, [...array_fill(0, 32, $target), ...array_fill(0, 31, $other)], 'bank', $today);
        foreach ($rows as $op) {
            $op->setDescription('Оплата по договору')->setCounterparty($party);
            $this->em()->persist($op);
            $this->em()->persist($this->manualCategoryAudit($op, $user, $today));
        }
        $this->em()->flush();
        $history = static::getContainer()->get(CashTransactionAutoRuleCandidateQuery::class);
        $args = [(string) $company->getId(), (string) $party->getId(), $rows[0]->getDirection()->value];
        self::assertCount(63, iterator_to_array($history->phraseHistory(...$args)));
        $sample = iterator_to_array($history->phraseHistory(...$args, sampleForCategory: $target->getId()));
        self::assertCount(60, $sample);
        self::assertSame([$target->getId() => 30, $other->getId() => 30], array_count_values(array_column($sample, 'categoryId')));
        self::assertNotContains($rows[31]->getId(), array_column($sample, 'id'));
        self::assertNotContains($rows[62]->getId(), array_column($sample, 'id'));
    }

    private function account(Company $company, int $index, string $name): MoneyAccount
    {
        return MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withName($name)
            ->forCompany($company)
            ->build();
    }

    /**
     * @param list<CashflowCategory> $categories
     *
     * @return list<CashTransaction>
     */
    private function transactions(
        Company $company,
        MoneyAccount $account,
        array $categories,
        string $source,
        \DateTimeImmutable $today,
        bool $sameDay = false,
    ): array {
        $transactions = [];
        foreach ($categories as $index => $category) {
            $transaction = CashTransactionBuilder::aCashTransaction()
                ->forCompany($company)
                ->withMoneyAccount($account)
                ->withCashflowCategory($category)
                ->build();
            $transaction
                ->setOccurredAt($sameDay ? $today : $today->modify(sprintf('-%d days', $index)))
                ->setImportSource($source);
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    private function manualCategoryAudit(
        CashTransaction $transaction,
        User $actor,
        \DateTimeImmutable $createdAt,
        bool $nested = false,
    ): AuditLog {
        $change = [null, $transaction->getCashflowCategory()?->getId()];

        return new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            $nested ? ['changes' => ['cashflowCategory' => $change]] : ['cashflowCategory' => $change],
            $actor->getId(),
            $createdAt,
        );
    }
}
