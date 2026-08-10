<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Application\DTO\CreateCashTransferResult;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyCashTransfersCommandTest extends IntegrationTestCase
{
    public function testGreenRunAndReportsLegacyLegAsInfo(): void
    {
        [$cross, $company, $sourceAccount] = $this->createTransfer('RUB', 'USD', '9500.00', '100.00');
        $this->createTransfer('EUR', 'EUR', '50.00', '50.00');
        $this->createTransfer('USD', 'RUB', '1234.56', '98765.43');

        $legacy = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $sourceAccount,
            CashDirection::OUTFLOW,
            '1.00',
            'RUB',
            new \DateTimeImmutable('2026-08-09'),
        );
        $legacy->setIsTransfer(true);
        $this->em->persist($legacy);
        $this->em->flush();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $output);
        self::assertStringContainsString('агрегатов: 3', $output);
        self::assertStringContainsString('Legacy isTransfer=true без агрегата: 1 (INFO', $output);
        self::assertStringContainsString('Сверка переводов пройдена', $output);
        self::assertStringNotContainsString($cross->transferId, $output);
        self::assertStringNotContainsString((string) $company->getId(), $output);
        self::assertStringNotContainsString('9500.00', $output);
    }

    public function testBrokenAggregateFailsWithoutRepairOrSensitiveDiagnostics(): void
    {
        [$result, $company, $sourceAccount] = $this->createTransfer('RUB', 'USD', '9500.00', '100.00');
        $ordinaryCategory = (new CashflowCategory(Uuid::uuid4()->toString(), $company))->setName('Диагностика');
        $this->em->persist($ordinaryCategory);
        $this->em->flush();

        $this->connection->executeStatement(
            'UPDATE cash_transaction SET direction = :direction, deleted_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['direction' => CashDirection::INFLOW->value, 'id' => $result->sourceTransactionId],
        );
        $this->connection->executeStatement(
            'UPDATE cash_transaction_split SET cashflow_category_id = :categoryId WHERE cash_transaction_id = :transactionId',
            ['categoryId' => $ordinaryCategory->getId(), 'transactionId' => $result->sourceTransactionId],
        );
        $this->connection->executeStatement(
            'UPDATE cash_transfer SET effective_rate = :rate WHERE id = :id',
            ['rate' => '1.000000000000000000', 'id' => $result->transferId],
        );
        $this->connection->executeStatement(
            'UPDATE money_account SET opening_balance_date = :date WHERE id = :id',
            ['date' => '2027-01-01', 'id' => $sourceAccount->getId()],
        );

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $output);
        self::assertStringContainsString('account_contract', $output);
        self::assertStringContainsString('leg_contract', $output);
        self::assertStringContainsString('technical_splits', $output);
        self::assertStringContainsString('fx_metadata', $output);
        self::assertStringContainsString('deletion_state', $output);
        self::assertStringContainsString('Команда ничего не изменила', $output);
        self::assertStringNotContainsString($result->transferId, $output);
        self::assertStringNotContainsString((string) $company->getId(), $output);
        self::assertSame(
            CashDirection::INFLOW->value,
            $this->connection->fetchOne('SELECT direction FROM cash_transaction WHERE id = :id', ['id' => $result->sourceTransactionId]),
        );
        self::assertSame(
            '1.000000000000000000',
            $this->connection->fetchOne('SELECT effective_rate FROM cash_transfer WHERE id = :id', ['id' => $result->transferId]),
        );
    }

    public function testCrossRoleLegReuseIsRejected(): void
    {
        [$result, $company] = $this->createTransfer('RUB', 'USD', '9500.00', '100.00');

        $this->connection->insert('cash_transfer', [
            'id' => Uuid::uuid4()->toString(),
            'company_id' => $company->getId(),
            'source_transaction_id' => $result->targetTransactionId,
            'target_transaction_id' => $result->sourceTransactionId,
            'idempotency_key' => 'verify-reused-cross-role',
            'effective_rate' => '95.000000000000000000',
            'rate_base_currency' => 'USD',
            'rate_quote_currency' => 'RUB',
            'rate_date' => '2026-08-09',
            'rate_source' => CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE,
            'created_at' => '2026-08-09 12:00:00',
            'updated_at' => '2026-08-09 12:00:00',
        ]);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/leg_ownership\s+[^\n]*2\s+FAIL/', $tester->getDisplay());
    }

    /** @return array{CreateCashTransferResult, Company, MoneyAccount} */
    private function createTransfer(
        string $sourceCurrency,
        string $targetCurrency,
        string $sourceAmount,
        string $targetAmount,
    ): array {
        $suffix = Uuid::uuid4()->toString();
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('verify-transfer-'.$suffix.'@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->withName('Verify transfer '.$suffix)
            ->build();
        $sourceAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Source '.$suffix,
            $sourceCurrency,
        );
        $targetAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::EWALLET,
            'Target '.$suffix,
            $targetCurrency,
        );
        $sourceAccount->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $targetAccount->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        foreach ([$user, $company, $sourceAccount, $targetAccount, $project, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));
        $this->em->flush();
        self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $this->em->flush();

        $result = self::getContainer()->get(CashFacade::class)->createTransfer(new CreateCashTransferCommand(
            $company->getId(),
            (string) $sourceAccount->getId(),
            (string) $targetAccount->getId(),
            $sourceAmount,
            $targetAmount,
            new \DateTimeImmutable('2026-08-09'),
            'verify-'.$suffix,
        ));

        return [$result, $company, $sourceAccount];
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cash:verify-transfers'));
        $tester->execute([]);

        return $tester;
    }
}
