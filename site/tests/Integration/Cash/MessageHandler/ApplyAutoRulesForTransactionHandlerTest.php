<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\MessageHandler;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\MessageHandler\ApplyAutoRulesForTransactionHandler;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class ApplyAutoRulesForTransactionHandlerTest extends IntegrationTestCase
{
    public function testDoesNotLoadTransactionOutsideMessageCompany(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $transaction = $this->createTransaction($company, $account);

        foreach ([$user, $company, $account, $transaction] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::never())->method('getSkipReason');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Cash auto rules: transaction not found',
                self::callback(static fn (array $context): bool => isset($context['correlationId'])
                    && Uuid::isValid($context['correlationId'])),
            );
        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            self::getContainer()->get(CashTransactionRepository::class),
            $autoRuleService,
            self::getContainer()->get(CashflowSystemCategoryService::class),
            self::getContainer()->get(AutoRuleDispatchGuard::class),
            $logger,
        );

        $handler(new ApplyAutoRulesForTransaction(
            (string) $transaction->getId(),
            '11111111-1111-1111-1111-999999999999',
            new \DateTimeImmutable(),
        ));

        self::assertNull($transaction->getCashflowCategory());
    }

    public function testSkipsDeletedTransactionBeforeMatchingOrFallbackCategory(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $transaction = $this->createTransaction($company, $account);
        $transaction->markDeleted(null);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($transaction);
        $this->em->flush();

        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::once())
            ->method('getSkipReason')
            ->with($transaction)
            ->willReturn(CashTransactionAutoRuleSkipReason::DELETED);
        $autoRuleService->expects(self::never())->method('match');
        $autoRuleService->expects(self::never())->method('applyMatch');

        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            self::getContainer()->get(CashTransactionRepository::class),
            $autoRuleService,
            self::getContainer()->get(CashflowSystemCategoryService::class),
            self::getContainer()->get(AutoRuleDispatchGuard::class),
            new NullLogger(),
        );

        $handler(new ApplyAutoRulesForTransaction(
            (string) $transaction->getId(),
            (string) $company->getId(),
            new \DateTimeImmutable(),
        ));

        self::assertNull($transaction->getCashflowCategory());
    }

    public function testAssignsUnallocatedAndReusesSingleSystemCategoryForCompany(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();

        $firstTransaction = $this->createTransaction($company, $account);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($firstTransaction);
        $this->em->flush();

        $transactionRepository = self::getContainer()->get(CashTransactionRepository::class);
        $cashflowSystemCategoryService = self::getContainer()->get(CashflowSystemCategoryService::class);

        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::exactly(2))
            ->method('match')
            ->willReturn(new CashTransactionAutoRuleMatchResult(null));
        $autoRuleService->expects(self::exactly(2))->method('applyMatch')->willReturn(null);

        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            $transactionRepository,
            $autoRuleService,
            $cashflowSystemCategoryService,
            self::getContainer()->get(AutoRuleDispatchGuard::class),
            new NullLogger(),
        );

        $handler(new ApplyAutoRulesForTransaction(
            $firstTransaction->getId() ?? '',
            $company->getId() ?? '',
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        ));

        $firstReloaded = $transactionRepository->find($firstTransaction->getId());
        self::assertInstanceOf(CashTransaction::class, $firstReloaded);
        self::assertNotNull($firstReloaded->getCashflowCategory());
        self::assertSame(CashflowCategory::CODE_UNALLOCATED, $firstReloaded->getCashflowCategory()?->getCode());
        self::assertSame('Не распределено', $firstReloaded->getCashflowCategory()?->getName());

        $reloadedCompany = $this->em->find(Company::class, $company->getId());
        $reloadedAccount = $this->em->find(MoneyAccount::class, $account->getId());
        self::assertInstanceOf(Company::class, $reloadedCompany);
        self::assertInstanceOf(MoneyAccount::class, $reloadedAccount);

        $secondTransaction = $this->createTransaction($reloadedCompany, $reloadedAccount);
        $this->em->persist($secondTransaction);
        $this->em->flush();

        $handler(new ApplyAutoRulesForTransaction(
            $secondTransaction->getId() ?? '',
            $company->getId() ?? '',
            new \DateTimeImmutable('2024-01-01T00:01:00+00:00'),
        ));

        $secondReloaded = $transactionRepository->find($secondTransaction->getId());
        self::assertInstanceOf(CashTransaction::class, $secondReloaded);

        $firstCategoryId = $firstReloaded->getCashflowCategory()?->getId();
        $secondCategoryId = $secondReloaded->getCashflowCategory()?->getId();

        self::assertNotNull($secondCategoryId);
        self::assertSame($firstCategoryId, $secondCategoryId);

        $categoryCount = (int) $this->em->getRepository(CashflowCategory::class)->count([
            'company' => $company,
            'systemCode' => CashflowCategory::CODE_UNALLOCATED,
        ]);
        self::assertSame(1, $categoryCount);
    }

    public function testReclassifiesUnallocatedTransactionWithFillRule(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $unallocated = (new CashflowCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Не распределено')
            ->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $targetCategory = (new CashflowCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Аренда');
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $targetCategory,
        );
        $transaction = $this->createTransaction($company, $account)
            ->setCashflowCategory($unallocated);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($unallocated);
        $this->em->persist($targetCategory);
        $this->em->persist($rule);
        $this->em->persist($transaction);
        $this->em->flush();

        $loggedCorrelationIds = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$loggedCorrelationIds): void {
                if ('Cash auto rules applied' === $message) {
                    $loggedCorrelationIds[] = $context['correlationId'] ?? null;
                }
            },
        );
        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            self::getContainer()->get(CashTransactionRepository::class),
            self::getContainer()->get(CashTransactionAutoRuleService::class),
            self::getContainer()->get(CashflowSystemCategoryService::class),
            self::getContainer()->get(AutoRuleDispatchGuard::class),
            $logger,
        );
        $correlationId = Uuid::uuid7()->toString();

        $message = new ApplyAutoRulesForTransaction(
            $transaction->getId() ?? '',
            $company->getId() ?? '',
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            $correlationId,
        );
        $handler($message);
        $handler($message);

        $reloaded = $this->em->find(CashTransaction::class, $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertSame($targetCategory->getId(), $reloaded->getCashflowCategory()?->getId());

        $applicationAuditLogs = array_filter(
            $this->em->getRepository(AuditLog::class)->findBy(['entityId' => $transaction->getId()]),
            static fn (AuditLog $auditLog): bool => $rule->getId()
                === ($auditLog->getDiff()['autoRules']['cashflowCategory']['id'] ?? null),
        );
        self::assertCount(1, $applicationAuditLogs);
        $applicationAuditLog = reset($applicationAuditLogs);
        self::assertInstanceOf(AuditLog::class, $applicationAuditLog);
        self::assertSame([$correlationId, $correlationId], $loggedCorrelationIds);
        self::assertSame([
            'correlationId' => $correlationId,
            'autoRules' => [
                'cashflowCategory' => [
                    'id' => $rule->getId(),
                    'revision' => 1,
                ],
            ],
            'changes' => [
                'cashflowCategory' => [
                    'before' => $unallocated->getId(),
                    'after' => $targetCategory->getId(),
                ],
            ],
        ], $applicationAuditLog->getDiff());
    }

    public function testConflictSkipsCategoryButAppliesProject(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $firstCategory = (new CashflowCategory(Uuid::uuid4()->toString(), $company))->setName('Аренда');
        $secondCategory = (new CashflowCategory(Uuid::uuid4()->toString(), $company))->setName('Комиссия');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $firstRule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $firstCategory,
        );
        $firstRule->setProjectDirection($project);
        $secondRule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Комиссия',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $secondCategory,
        );
        $secondRule->setProjectDirection($project);
        $transaction = $this->createTransaction($company, $account);

        foreach ([$user, $company, $account, $firstCategory, $secondCategory, $project, $firstRule, $secondRule, $transaction] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            self::getContainer()->get(CashTransactionRepository::class),
            self::getContainer()->get(CashTransactionAutoRuleService::class),
            self::getContainer()->get(CashflowSystemCategoryService::class),
            self::getContainer()->get(AutoRuleDispatchGuard::class),
            new NullLogger(),
        );

        $handler(new ApplyAutoRulesForTransaction(
            $transaction->getId() ?? '',
            $company->getId() ?? '',
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        ));

        $reloaded = $this->em->find(CashTransaction::class, $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertSame(CashflowCategory::CODE_UNALLOCATED, $reloaded->getCashflowCategory()?->getCode());
        self::assertSame($project->getId(), $reloaded->getProjectDirection()?->getId());
    }

    private function createTransaction(Company $company, MoneyAccount $account): CashTransaction
    {
        return new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2024-01-02'),
        );
    }
}
