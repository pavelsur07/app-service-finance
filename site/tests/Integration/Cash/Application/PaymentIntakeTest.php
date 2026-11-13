<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\DTO\PaymentIntake\AmendPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\ConfirmPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use App\Cash\Application\DTO\PaymentIntake\RequestPaymentIntakeCommand;
use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\PaymentIntake\PaymentIntakeResultConsumer;
use App\Cash\Application\Service\Transaction\CashTransactionService;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

final class PaymentIntakeTest extends IntegrationTestCase
{
    private User $user;
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $category;
    private AiTaskFacadeInterface&MockObject $ai;
    private PaymentIntakeAction $action;
    /** @var list<array{key:string,payload:array<string,mixed>,task:string}> */
    private array $requests = [];
    private bool $failEnqueue = false;
    /** @var list<string> */
    private array $failingDraftIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = UserBuilder::aUser()->withEmail('payment-intake@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($this->user)->build();
        $this->account = new MoneyAccount(Uuid::uuid7()->toString(), $this->company, MoneyAccountType::BANK, 'Intake', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $this->company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($this->companyId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $pair = new FinancialResponsibilityCenterProject($this->companyId(), $project, $center);
        $this->category = new CashflowCategory(Uuid::uuid7()->toString(), $this->company);
        $this->category->setName('Аренда');
        foreach ([$this->user, $this->company, $this->account, $project, $center, $pair, $this->category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->ai = $this->createMock(AiTaskFacadeInterface::class);
        $this->ai->method('isEnabledForCompany')->willReturn(true);
        $idFor = static fn (string $company, string $type, string $key): string => Uuid::uuid5(Uuid::NAMESPACE_URL, $company.'/'.$type.'/'.$key)->toString();
        $this->ai->method('taskIdFor')->willReturnCallback($idFor);
        $this->ai->method('requestTask')->willReturnCallback(function (string $company, string $type, array $payload, string $key) use ($idFor): string {
            $task = $idFor($company, $type, $key);
            $this->requests[] = ['key' => $key, 'payload' => $payload, 'task' => $task];
            if ($this->failEnqueue || \in_array(explode('/', $key)[0], $this->failingDraftIds, true)) {
                throw new \RuntimeException('Test broker failure');
            }

            return $task;
        });
        $this->buildAction();
    }

    public function testPreviewDefaultsAndExplicitConfirmationCreateExactlyOneOperation(): void
    {
        $draft = $this->ready();
        self::assertSame(0, $this->ledgerCount());
        self::assertSame('2026-11-13', $draft->items[0]['date']);
        self::assertSame('RUB', $draft->items[0]['currency']);
        self::assertSame('message_date_default', $draft->items[0]['provenance']['date']);
        self::assertFalse($draft->items[0]['paid_confirmed']);
        self::assertNotNull($draft->items[0]['project_id']);
        self::assertNotNull($draft->items[0]['cfo_id']);
        self::assertSame('system_general_default', $draft->items[0]['provenance']['project_id']);
        $draft = $this->approve($draft);
        $command = new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, $draft->revision, [$draft->items[0]['id']]);
        $first = $this->action->confirm($command);
        $second = $this->action->confirm($command);
        self::assertSame($first->transactionIds, $second->transactionIds);
        self::assertSame(1, $this->ledgerCount());
        self::assertSame('100.00', $this->connection->fetchOne('SELECT amount FROM cash_transaction WHERE id=?', [$first->transactionIds[0]]));
    }

    public function testResultReplayAndStaleAttemptCannotReplaceRows(): void
    {
        $draft = $this->ready();
        $firstId = $draft->items[0]['id'];
        $this->deliverResult($draft->taskId);
        self::assertSame($firstId, $this->get($draft)->items[0]['id']);
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, [$firstId => ['amount' => '250.00']]));
        $this->deliverResult($draft->taskId);
        self::assertSame('250.00', $this->get($draft)->items[0]['amount']);
        self::assertSame('user', $this->get($draft)->items[0]['provenance']['amount']);
    }

    public function testActorCannotUseAnotherActorsDraft(): void
    {
        $draft = $this->ready();
        $other = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('other-intake@example.com')->build();
        $this->em->persist($other);
        $this->em->persist(new \App\Company\Entity\CompanyMember(Uuid::uuid7()->toString(), $this->company, $other, \App\Company\Entity\CompanyMember::ROLE_OWNER));
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->action->get($this->companyId(), (string) $other->getId(), $draft->id);
    }

    public function testGlobalSourceCannotBeReboundToAnotherCompany(): void
    {
        $this->ready();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($this->user)->withName('Other')->build();
        $this->em->persist($other);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->action->request(new RequestPaymentIntakeCommand((string) $other->getId(), $this->actorId(), (string) $this->account->getId(), '1', '2', '3', 'оплатил 100', new \DateTimeImmutable()));
    }

    public function testBrokerFailureRecoveryUsesSameDurableTaskAndOriginalDate(): void
    {
        $this->failEnqueue = true;
        try {
            $this->request();
            self::fail('Broker must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Test broker failure', $e->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id=?', [$this->companyId()]));
        $this->failEnqueue = false;
        self::assertSame(1, $this->action->recover($this->companyId()));
        self::assertSame($this->requests[0]['key'], $this->requests[1]['key']);
        self::assertSame($this->requests[0]['task'], $this->requests[1]['task']);
        self::assertEquals($this->requests[0]['payload'], $this->requests[1]['payload'], 'JSONB object key order is immaterial; payload values and array order must remain equal.');
        $draft = $this->request();
        self::assertSame('queued', $draft->status);
        self::assertCount(2, $this->requests);
    }

    public function testTerminalFailureExplicitRetryCreatesOnlyOneNewAttemptAndIgnoresOldAnswer(): void
    {
        $draft = $this->request();
        $oldTask = $draft->taskId;
        $this->deliverResult($oldTask, AiResultStatus::FAILED);
        $command = new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, retry: true);
        $retried = $this->action->amend($command);
        self::assertNotSame($oldTask, $retried->taskId);
        $this->deliverResult($oldTask);
        self::assertSame([], $this->get($retried)->items);
        $this->deliverResult($retried->taskId);
        self::assertCount(1, $this->get($retried)->items);
        self::assertCount(2, $this->requests);
    }

    public function testClosedPeriodRecheckedAfterPreview(): void
    {
        $draft = $this->approve($this->ready());
        $this->connection->executeStatement('UPDATE companies SET finance_lock_before=? WHERE id=?', ['2026-11-14', $this->companyId()]);
        $this->expectException(\DomainException::class);
        $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, $draft->revision, [$draft->items[0]['id']]));
    }

    public function testSecondRowFailureRollsBackWholeBatchAndDraftStatus(): void
    {
        $draft = $this->request();
        $this->deliverResult($draft->taskId, count: 2);
        $draft = $this->get($draft);
        $patches = [];
        foreach ($draft->items as $item) {
            $patches[$item['id']] = ['paid_confirmed' => true];
        }
        $patches[$draft->items[1]['id']]['date'] = '2026-11-12';
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, $patches));
        $this->connection->executeStatement('UPDATE companies SET finance_lock_before=? WHERE id=?', ['2026-11-13', $this->companyId()]);
        try {
            $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 2, array_column($draft->items, 'id')));
            self::fail('Second row must fail.');
        } catch (\DomainException $e) {
            self::assertSame('Период платежа закрыт.', $e->getMessage());
        }
        self::assertSame(0, $this->ledgerCount());
        self::assertSame($draft->status, $this->connection->fetchOne('SELECT status FROM cash_payment_intake_draft WHERE id=?', [$draft->id]));
    }

    public function testCancelledDraftNeverPostsLedger(): void
    {
        $draft = $this->ready();
        $this->action->cancel($this->companyId(), $this->actorId(), $draft->id, 1);
        self::assertSame('cancelled', $this->get($draft)->status);
        self::assertSame(0, $this->ledgerCount());
    }

    public function testCategoryArchivedAfterPreviewCannotBePosted(): void
    {
        $draft = $this->ready();
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, [$draft->items[0]['id'] => ['category_id' => (string) $this->category->getId(), 'paid_confirmed' => true]]));
        $this->category->setStatus(CashflowCategoryStatus::DISABLED);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 2, [$draft->items[0]['id']]));
    }

    public function testNullableAmountEvidencePreservesClarificationCandidate(): void
    {
        $draft = $this->request();
        $item = $this->candidate();
        $item['amount'] = null;
        $item['amount_evidence'] = null;
        $item['questions'] = ['Укажите сумму.'];
        $this->deliverResult($draft->taskId, output: ['intent' => 'payments', 'items' => [$item], 'questions' => [], 'target_ref' => null]);
        $view = $this->get($draft);
        self::assertSame('needs_clarification', $view->status);
        self::assertNull($view->items[0]['amount']);
        self::assertSame(['Укажите сумму.'], $view->items[0]['questions']);
    }

    public function testInventedEvidenceFailsClosed(): void
    {
        $draft = $this->request();
        $item = $this->candidate();
        $item['text_evidence'] = 'несуществующая цитата 100';
        $this->deliverResult($draft->taskId, output: ['intent' => 'payments', 'items' => [$item], 'questions' => [], 'target_ref' => null]);
        self::assertSame('contract_violation', $this->get($draft)->errorCode);
        self::assertSame([], $this->get($draft)->items);
    }

    public function testNaturalCorrectionKeepsOtherRowsAndManualAnalytics(): void
    {
        $draft = $this->request();
        $this->deliverResult($draft->taskId, count: 2);
        $draft = $this->get($draft);
        $first = $draft->items[0]['id'];
        $second = $draft->items[1]['id'];
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, [$first => ['category_id' => (string) $this->category->getId()]]));
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 2, text: 'исправь вторую сумму на 200', sourceRevision: 'edit-1'));
        self::assertSame([$first, $second], array_column($this->requests[1]['payload']['draft_items'], 'ref'));
        $correction = $this->candidate();
        $correction['ref'] = $second;
        $correction['text_evidence'] = '200';
        $correction['amount_evidence'] = '200';
        $correction['amount'] = '200.00';
        $correction['currency'] = null;
        $correction['date'] = null;
        $correction['direction'] = null;
        $correction['description'] = '';
        $this->deliverResult($draft->taskId, output: ['intent' => 'correction', 'items' => [$correction], 'questions' => [], 'target_ref' => $second]);
        $view = $this->get($draft);
        self::assertSame('100.00', $view->items[0]['amount']);
        self::assertSame((string) $this->category->getId(), $view->items[0]['category_id']);
        self::assertSame('user', $view->items[0]['provenance']['category_id']);
        self::assertSame('200.00', $view->items[1]['amount']);
        self::assertSame('RUB', $view->items[1]['currency']);
        self::assertSame('Аренда', $view->items[1]['description']);
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return ['ref' => 'p0', 'amount' => '100.00', 'currency' => null, 'date' => null, 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Аренда', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []];
    }

    public function testCacheEventFailureAfterCommitStillReturnsSavedConfirmationAndIsRecoverable(): void
    {
        $draft = $this->approve($this->ready());
        $events = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $events->method('dispatch')->willThrowException(new \RuntimeException('Test cache unavailable'));
        $this->buildAction($events);
        $confirmation = $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, $draft->revision, [$draft->items[0]['id']]));
        self::assertCount(1, $confirmation->transactionIds);
        self::assertSame(1, $this->ledgerCount());
        self::assertTrue((bool) $this->connection->fetchOne('SELECT cash_event_pending FROM cash_payment_intake_draft WHERE id=?', [$draft->id]));
        $this->buildAction();
        self::assertSame(1, $this->action->recover($this->companyId()));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT cash_event_pending FROM cash_payment_intake_draft WHERE id=?', [$draft->id]));
    }

    public function testSimilarPaymentsAreWarnedAboutButNeverAutomaticallyDiscarded(): void
    {
        $first = $this->approve($this->ready());
        $confirmation = $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $first->id, $first->revision, [$first->items[0]['id']]));
        $second = $this->request('4');
        $this->deliverResult($second->taskId);
        $second = $this->get($second);
        self::assertSame($confirmation->transactionIds, $second->items[0]['possible_duplicate_ids']);
        self::assertNotEmpty($second->items[0]['warnings']);
        $second = $this->approve($second);
        $this->action->confirm(new ConfirmPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $second->id, $second->revision, [$second->items[0]['id']]));
        self::assertSame(2, $this->ledgerCount());
    }

    public function testUnknownModelReferencesBecomeQuestionsInsteadOfPoisoningOtherRows(): void
    {
        $draft = $this->request();
        $invalid = $this->candidate();
        $invalid['ref'] = 'p1';
        $invalid['category_id'] = Uuid::uuid7()->toString();
        $invalid['project_id'] = Uuid::uuid7()->toString();
        $this->deliverResult($draft->taskId, output: ['intent' => 'payments', 'items' => [$this->candidate(), $invalid], 'questions' => [], 'target_ref' => null]);
        $draft = $this->get($draft);
        self::assertNull($draft->items[1]['category_id']);
        self::assertNotSame($invalid['project_id'], $draft->items[1]['project_id']);
        self::assertNotEmpty($draft->items[1]['questions']);
        $changed = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, $draft->revision, [$draft->items[0]['id'] => ['paid_confirmed' => true]]));
        self::assertTrue($changed->items[0]['paid_confirmed']);
    }

    public function testUnpatchedStaleReferenceDoesNotBlockAnotherRowsAmendment(): void
    {
        $draft = $this->request();
        $this->deliverResult($draft->taskId, count: 2);
        $draft = $this->get($draft);
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 1, [$draft->items[1]['id'] => ['category_id' => (string) $this->category->getId()]]));
        $this->category->setStatus(CashflowCategoryStatus::DISABLED);
        $this->em->flush();
        $draft = $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, 2, [$draft->items[0]['id'] => ['paid_confirmed' => true]]));
        self::assertTrue($draft->items[0]['paid_confirmed']);
        self::assertSame((string) $this->category->getId(), $draft->items[1]['category_id']);
    }

    public function testRecoveryIsolatesFailuresAndPagesPastFirstHundredPendingDrafts(): void
    {
        $this->failEnqueue = true;
        for ($i = 0; $i < 101; ++$i) {
            try {
                $this->request((string) (1000 + $i));
                self::fail('Broker must reject initial delivery.');
            } catch (\RuntimeException) {
                $draft = $this->action->getBySource('1', '2', (string) (1000 + $i), $this->actorId());
                self::assertNotNull($draft);
                if ($i < 100) {
                    $this->failingDraftIds[] = $draft->id;
                }
            }
        }
        $this->failEnqueue = false;
        self::assertSame(1, $this->action->recover($this->companyId()));
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id=? AND dispatch_pending=true', [$this->companyId()]));
        self::assertSame(0, $this->action->recover($this->companyId()));
        $this->failingDraftIds = [];
        self::assertSame(100, $this->action->recover($this->companyId()));
    }

    private function buildAction(?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events = null): void
    {
        $c = self::getContainer();
        $this->action = new PaymentIntakeAction($this->em, $c->get(PaymentIntakeDraftRepository::class), $c->get(CompanyFacade::class), $c->get(FinancialResponsibilityCenterFacade::class), $c->get(CashTransactionService::class), $c->get(CashTransactionResponsibilityCenterResolver::class), $c->get(CashCategorizationQuery::class), $this->ai, $c->get(AddCashTransactionAction::class), $events ?? $c->get('event_dispatcher'), new \Psr\Log\NullLogger(), $c->get(\App\Cash\Infrastructure\Query\PaymentIntakeDuplicateQuery::class));
    }

    private function request(string $messageId = '3'): PaymentIntakeDraftView
    {
        return $this->action->request(new RequestPaymentIntakeCommand($this->companyId(), $this->actorId(), (string) $this->account->getId(), '1', '2', $messageId, 'оплатил 100', new \DateTimeImmutable('2026-11-13T00:30:00+03:00')));
    }

    private function ready(): PaymentIntakeDraftView
    {
        $draft = $this->request();
        $this->deliverResult($draft->taskId);

        return $this->get($draft);
    }

    private function approve(PaymentIntakeDraftView $draft): PaymentIntakeDraftView
    {
        return $this->action->amend(new AmendPaymentIntakeDraftCommand($this->companyId(), $this->actorId(), $draft->id, $draft->revision, [$draft->items[0]['id'] => ['paid_confirmed' => true]]));
    }

    private function get(PaymentIntakeDraftView $draft): PaymentIntakeDraftView
    {
        return $this->action->get($this->companyId(), $this->actorId(), $draft->id);
    }

    /** @param array<string, mixed>|null $output */
    private function deliverResult(?string $taskId, AiResultStatus $status = AiResultStatus::ACCEPTED, int $count = 1, ?array $output = null): void
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = ['ref' => 'p'.$i, 'amount' => '100.00', 'currency' => null, 'date' => null, 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Аренда', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []];
        }
        $result = new AiTaskResult((string) $taskId, PaymentIntakeAction::TASK_TYPE, $this->companyId(), $status, $output ?? ['intent' => 'payments', 'items' => $items, 'questions' => [], 'target_ref' => null], [], null, Uuid::uuid7()->toString(), ['stub'], 'parse_payment_message/v1', 1, 1, null, new \DateTimeImmutable());
        $consumer = self::getContainer()->get(PaymentIntakeResultConsumer::class);
        $this->em->wrapInTransaction(static fn () => $consumer->consume($result));
    }

    private function actorId(): string
    {
        return (string) $this->user->getId();
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }

    private function ledgerCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id=?', [$this->companyId()]);
    }
}
