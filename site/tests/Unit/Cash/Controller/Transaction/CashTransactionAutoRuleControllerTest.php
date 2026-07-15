<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Controller\Transaction\CashTransactionAutoRuleController;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
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
        $transactionRepository->method('findOneByIdAndCompanyId')
            ->with($transaction->getId(), $company->getId())
            ->willReturn($transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->with($transaction)->willReturn(null);
        $autoRuleService->method('match')->with($transaction)->willReturn(new CashTransactionAutoRuleMatchResult($winner));
        $autoRuleService->expects(self::never())->method('applyRule');
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects(self::once())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool => sprintf('apply-auto-rule%s', $transaction->getId()) === $token->getId()
                && 'valid-token' === $token->getValue()))
            ->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['ruleId' => Uuid::uuid4()->toString(), '_token' => 'valid-token']),
            $companyService,
            $transactionRepository,
            $autoRuleService,
            $csrfTokenManager,
            $entityManager,
            new AutoRuleDispatchGuard(),
        );

        self::assertSame([
            'ok' => false,
            'message' => 'Подходящее правило не найдено',
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testManualApplyFlushesOnceWithDispatchSuppressed(): void
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
        $transactionRepository->method('findOneByIdAndCompanyId')
            ->with($transaction->getId(), $company->getId())
            ->willReturn($transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->willReturn(null);
        $match = new CashTransactionAutoRuleMatchResult($rule);
        $autoRuleService->method('match')->willReturn($match);
        $autoRuleService->expects(self::once())->method('applyRule')->with($rule, $transaction, $match)->willReturn(true);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $dispatchGuard = new AutoRuleDispatchGuard();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use ($dispatchGuard): void {
                self::assertTrue($dispatchGuard->isSuppressed());
            });

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['ruleId' => $rule->getId(), '_token' => 'valid-token']),
            $companyService,
            $transactionRepository,
            $autoRuleService,
            $csrfTokenManager,
            $entityManager,
            $dispatchGuard,
        );

        self::assertTrue(json_decode((string) $response->getContent(), true)['changed']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCsrfTokenProvider')]
    public function testManualApplyRejectsInvalidCsrfTokenBeforeLoadingTransaction(string $submittedToken): void
    {
        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->expects(self::never())->method('getActiveCompany');
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->expects(self::never())->method('findOneByIdAndCompanyId');
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->expects(self::never())->method('match');
        $autoRuleService->expects(self::never())->method('applyRule');
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            Uuid::uuid4()->toString(),
            Request::create('/', 'POST', ['_token' => $submittedToken]),
            $companyService,
            $transactionRepository,
            $autoRuleService,
            $csrfTokenManager,
            $entityManager,
            new AutoRuleDispatchGuard(),
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
}
