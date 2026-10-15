<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Controller\Transaction;

use App\Cash\Application\ApplyAutoRuleToTransactionAction;
use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Controller\Transaction\CashTransactionAutoRuleController;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Company\Entity\Company;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Repository\AuditLogRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CashTransactionAutoRuleControllerTest extends TestCase
{
    public function testManualApplyRejectsRuleIdThatIsNotTheCurrentWinner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $winner = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Winner',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->method('findOneByIdAndCompanyIdForUpdate')
            ->with($transaction->getId(), $company->getId())
            ->willReturn($transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->with($transaction)->willReturn(null);
        $autoRuleService->method('match')->with($transaction)->willReturn(new CashTransactionAutoRuleMatchResult(
            $winner,
            winners: ['cashflowCategory' => $winner],
        ));
        $autoRuleService->expects(self::never())->method('applyRule');
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects(self::once())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool => sprintf('apply-auto-rule%s', $transaction->getId()) === $token->getId()
                && 'valid-token' === $token->getValue()))
            ->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $auditContextProvider = $this->createMock(AuditContextProvider::class);
        $auditContextProvider->expects(self::never())->method('getActorUserId');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['ruleId' => Uuid::uuid4()->toString(), '_token' => 'valid-token']),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, new AutoRuleDispatchGuard(), $auditContextProvider),
        );

        self::assertSame([
            'ok' => false,
            'message' => 'Подходящее правило не найдено',
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testManualApplyFlushesOnceWithPartialConflictAndDispatchSuppressed(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Winner',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->method('findOneByIdAndCompanyIdForUpdate')
            ->with($transaction->getId(), $company->getId())
            ->willReturn($transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->willReturn(null);
        $conflictingRule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Conflicting project rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $rule->getCashflowCategory(),
        );
        $match = new CashTransactionAutoRuleMatchResult(
            $rule,
            [$conflictingRule],
            winners: ['cashflowCategory' => $rule],
            conflicts: ['projectDirection' => [$conflictingRule]],
        );
        $applicationPlan = new CashTransactionAutoRuleApplicationPlan(
            $rule,
            ['cashflowCategory' => ['before' => null, 'after' => $rule->getCashflowCategory()?->getId()]],
            $rule->getCashflowCategory(),
            null,
            null,
        );
        $autoRuleService->method('match')->willReturn($match);
        $autoRuleService->expects(self::once())
            ->method('applyRule')
            ->with($rule, $transaction, $match)
            ->willReturn($applicationPlan);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $dispatchGuard = new AutoRuleDispatchGuard();
        $actorUserId = Uuid::uuid4()->toString();
        $auditContextProvider = $this->createMock(AuditContextProvider::class);
        $auditContextProvider->method('getActorUserId')->willReturn($actorUserId);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $inTransaction = false;
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $operation) use (&$inTransaction): mixed {
                $inTransaction = true;
                try {
                    return $operation();
                } finally {
                    $inTransaction = false;
                }
            },
        );
        // GlitchTip #414: блокирующее чтение — внутри транзакции, иначе блокировка не держится до flush.
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->expects(self::once())
            ->method('findOneByIdAndCompanyIdForUpdate')
            ->with($transaction->getId(), $company->getId())
            ->willReturnCallback(static function () use (&$inTransaction, $transaction): CashTransaction {
                self::assertTrue($inTransaction, 'Транзакция ДДС читается под блокировкой вне wrapInTransaction.');

                return $transaction;
            });
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity) use ($company, $transaction, $applicationPlan, $actorUserId): bool {
                if (!$entity instanceof AuditLog) {
                    return false;
                }

                $diff = $entity->getDiff();
                $correlationId = $diff['correlationId'] ?? null;

                return $entity->getCompanyId() === $company->getId()
                    && CashTransaction::class === $entity->getEntityClass()
                    && $entity->getEntityId() === $transaction->getId()
                    && is_string($correlationId)
                    && Uuid::isValid($correlationId)
                    && $diff === $applicationPlan->auditDiff($correlationId)
                    && $entity->getActorUserId() === $actorUserId;
            }));
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use ($dispatchGuard, $applicationPlan): void {
                self::assertTrue($dispatchGuard->isSuppressed());
                self::assertSame($applicationPlan, $dispatchGuard->getApplicationPlan());
            });

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['ruleId' => $rule->getId(), '_token' => 'valid-token']),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, $dispatchGuard, $auditContextProvider),
        );

        self::assertTrue(json_decode((string) $response->getContent(), true)['changed']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCsrfTokenProvider')]
    public function testManualApplyRejectsInvalidCsrfTokenBeforeLoadingTransaction(string $submittedToken): void
    {
        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->expects(self::never())->method('getActiveCompany');
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->expects(self::never())->method('findOneByIdAndCompanyIdForUpdate');
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::never())->method('match');
        $autoRuleService->expects(self::never())->method('applyRule');
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $auditContextProvider = $this->createMock(AuditContextProvider::class);
        $auditContextProvider->expects(self::never())->method('getActorUserId');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            Uuid::uuid4()->toString(),
            Request::create('/', 'POST', ['_token' => $submittedToken]),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, new AutoRuleDispatchGuard(), $auditContextProvider),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([
            'ok' => false,
            'changed' => false,
            'reason' => 'invalid_csrf_token',
            'message' => 'Недействительный CSRF-токен.',
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCsrfTokenProvider(): iterable
    {
        yield 'invalid token' => ['invalid-token'];
        yield 'missing token' => [''];
    }

    public function testManualApplyReportsSkipReasonWithoutWriting(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        [$companyService, $transactionRepository, $csrfTokenManager, $entityManager] = $this->context($company, $transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->willReturn(CashTransactionAutoRuleSkipReason::MANUAL_SPLIT);
        $autoRuleService->expects(self::never())->method('match');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['_token' => 'valid-token']),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, new AutoRuleDispatchGuard(), $this->createMock(AuditContextProvider::class)),
        );

        self::assertSame([
            'ok' => false,
            'changed' => false,
            'reason' => CashTransactionAutoRuleSkipReason::MANUAL_SPLIT->value,
            'message' => CashTransactionAutoRuleSkipReason::MANUAL_SPLIT->label(),
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testManualApplyReportsFullConflictWithoutWriting(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        [$companyService, $transactionRepository, $csrfTokenManager, $entityManager] = $this->context($company, $transaction);
        $conflicting = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Conflicting',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->willReturn(null);
        $autoRuleService->method('match')->willReturn(new CashTransactionAutoRuleMatchResult(
            null,
            [$conflicting],
            conflicts: ['cashflowCategory' => [$conflicting]],
        ));
        $autoRuleService->expects(self::never())->method('applyRule');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['_token' => 'valid-token']),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, new AutoRuleDispatchGuard(), $this->createMock(AuditContextProvider::class)),
        );

        self::assertSame([
            'ok' => false,
            'changed' => false,
            'reason' => 'conflict',
            'message' => 'Все найденные поля конфликтуют и не будут изменены',
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{ActiveCompanyService&\PHPUnit\Framework\MockObject\MockObject, CashTransactionRepository&\PHPUnit\Framework\MockObject\MockObject, CsrfTokenManagerInterface&\PHPUnit\Framework\MockObject\MockObject, EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function context(Company $company, CashTransaction $transaction): array
    {
        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->method('findOneByIdAndCompanyIdForUpdate')->willReturn($transaction);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return [$companyService, $transactionRepository, $csrfTokenManager, $entityManager];
    }

    public function testManualApplyAnswersNotFoundForTransactionOutsideCompany(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->method('findOneByIdAndCompanyIdForUpdate')->willReturn(null);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::never())->method('match');
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        (new CashTransactionAutoRuleController())->applyOne(
            Uuid::uuid4()->toString(),
            Request::create('/', 'POST', ['_token' => 'valid-token']),
            $companyService,
            $csrfTokenManager,
            $this->action($entityManager, $transactionRepository, $autoRuleService, new AutoRuleDispatchGuard(), $this->createMock(AuditContextProvider::class)),
        );
    }

    private function action(
        EntityManagerInterface $entityManager,
        CashTransactionRepository $transactionRepository,
        CashTransactionAutoRuleService $autoRuleService,
        AutoRuleDispatchGuard $dispatchGuard,
        AuditContextProvider $auditContextProvider,
    ): ApplyAutoRuleToTransactionAction {
        return new ApplyAutoRuleToTransactionAction(
            $entityManager,
            $transactionRepository,
            $autoRuleService,
            $dispatchGuard,
            $auditContextProvider,
            new CashTransactionSplitSynchronizer($entityManager, $auditContextProvider, new AutoRuleDispatchGuard(), new CashTransactionAutoRuleProvenanceResolver($this->createMock(AuditLogRepository::class), new NullLogger())),
        );
    }
}
