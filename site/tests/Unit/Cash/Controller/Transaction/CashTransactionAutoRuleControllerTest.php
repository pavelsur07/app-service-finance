<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
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
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

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
        $transactionRepository->method('find')->with($transaction->getId())->willReturn($transaction);
        $autoRuleService = $this->createMock(CashTransactionAutoRuleService::class);
        $autoRuleService->method('getSkipReason')->with($transaction)->willReturn(null);
        $autoRuleService->method('match')->with($transaction)->willReturn(new CashTransactionAutoRuleMatchResult($winner));
        $autoRuleService->expects(self::never())->method('applyRule');

        $response = (new CashTransactionAutoRuleController())->applyOne(
            (string) $transaction->getId(),
            Request::create('/', 'POST', ['ruleId' => Uuid::uuid4()->toString()]),
            $companyService,
            $transactionRepository,
            $autoRuleService,
        );

        self::assertSame([
            'ok' => false,
            'message' => 'Подходящее правило не найдено',
        ], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }
}
