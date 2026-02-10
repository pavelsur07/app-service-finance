<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Entity\Company;
use App\Message\ApplyAutoRulesForTransaction;
use App\MessageHandler\ApplyAutoRulesForTransactionHandler;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class ApplyAutoRulesForTransactionHandlerTest extends IntegrationTestCase
{
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
            ->method('findMatchingRule')
            ->willReturn(null);
        $autoRuleService->expects(self::never())->method('applyRule');

        $handler = new ApplyAutoRulesForTransactionHandler(
            $this->em,
            $transactionRepository,
            $autoRuleService,
            $cashflowSystemCategoryService,
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
        self::assertSame(CashflowCategory::SYSTEM_UNALLOCATED, $firstReloaded->getCashflowCategory()?->getSystemCode());
        self::assertSame('Не распределено', $firstReloaded->getCashflowCategory()?->getName());

        $secondTransaction = $this->createTransaction($company, $account);
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
            'systemCode' => CashflowCategory::SYSTEM_UNALLOCATED,
        ]);
        self::assertSame(1, $categoryCount);
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
