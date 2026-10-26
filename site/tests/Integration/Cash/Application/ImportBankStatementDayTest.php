<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementDayResult;
use App\Cash\Application\DTO\BankStatementLineInput;
use App\Cash\Application\DTO\StatementImportSummary;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Exception\StatementImportOutsideTransactionException;
use App\Cash\Exception\StatementImportTargetNotFoundException;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Импорт дня банковской выписки через CashFacade — контракт для BankSync. Вызов — внутри
 * `wrapInTransaction()`, как из handler'а: внешнюю транзакцию DAMA DBAL не видит.
 */
final class ImportBankStatementDayTest extends IntegrationTestCase
{
    private const SOURCE = 'alfa';

    private Company $company;
    private MoneyAccount $account;
    private CashFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->account] = $this->createCompanyWithAccount('40702810900000000001');
        $this->em->flush();
        $this->facade = self::getContainer()->get(CashFacade::class);
    }

    public function testDayIsImportedWithSystemProjectAndRepeatedDayAddsNothing(): void
    {
        $day = $this->day([
            $this->line('uuid-1', true, '1500.50'),
            $this->line('uuid-2', false, '200.00', 'RUB'),
        ]);

        $first = $this->importDay($this->accountId(), self::SOURCE, $day);
        $second = $this->importDay($this->accountId(), self::SOURCE, $day);

        self::assertSame([2, 0, 0], [$first->created, $first->skippedDuplicates, $first->errors]);
        self::assertSame([0, 2, 0], [$second->created, $second->skippedDuplicates, $second->errors]);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT external_id, import_source, direction, amount, currency, project_direction_id, responsibility_center_id
             FROM cash_transaction WHERE company_id = :companyId ORDER BY external_id',
            ['companyId' => $this->companyId()],
        );
        self::assertCount(2, $rows);
        self::assertSame(['uuid-1', self::SOURCE, 'INFLOW', '1500.50', 'RUB'], array_slice(array_values($rows[0]), 0, 5));
        self::assertSame(['uuid-2', self::SOURCE, 'OUTFLOW', '200.00', 'RUB'], array_slice(array_values($rows[1]), 0, 5));
        self::assertNotNull($rows[0]['project_direction_id']);
        self::assertNotNull($rows[0]['responsibility_center_id']);
    }

    public function testDuplicateWithinDayIsStoredOnce(): void
    {
        $result = $this->importDay($this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-1', true, '10.00'),
            $this->line('uuid-1', true, '10.00'),
        ]));

        self::assertSame([1, 1], [$result->created, $result->skippedDuplicates]);
        self::assertSame(1, $this->countTransactions());
    }

    public function testLineDeletedByUserIsNotImportedAgain(): void
    {
        $this->importDay($this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-1', true, '10.00'),
        ]));
        $this->em->getConnection()->executeStatement(
            'UPDATE cash_transaction SET deleted_at = now() WHERE company_id = :companyId',
            ['companyId' => $this->companyId()],
        );

        $result = $this->importDay($this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-1', true, '10.00'),
        ]));

        self::assertSame([0, 1], [$result->created, $result->skippedDuplicates]);
    }

    public function testForeignCurrencyLineIsCountedAsErrorAndNotStored(): void
    {
        $result = $this->importDay($this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-usd', true, '10.00', 'USD'),
            $this->line('uuid-rub', true, '10.00', 'rub'),
        ]));

        self::assertSame([1, 0, 1], [$result->created, $result->skippedDuplicates, $result->errors]);
        self::assertSame(1, $this->countTransactions());
    }

    public function testAccountOfAnotherCompanyIsRejected(): void
    {
        [, $foreignAccount] = $this->createCompanyWithAccount('40702810900000000002');
        $this->em->flush();

        $this->expectException(StatementImportTargetNotFoundException::class);

        $this->importDay((string) $foreignAccount->getId(), self::SOURCE, $this->day([
            $this->line('uuid-1', true, '10.00'),
        ]));
    }

    /**
     * Регрессия: старый банковский импорт писал строки без пересчёта
     * `money_account_daily_balance`, и остатки счёта не видели загруженных операций.
     */
    public function testDailyBalancesAreRecalculatedFromFirstLineToToday(): void
    {
        $twoDaysAgo = new \DateTimeImmutable('-2 days 10:00');

        $this->importDay($this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-in', true, '1000.00', null, $twoDaysAgo),
            $this->line('uuid-out', false, '250.00', null, $twoDaysAgo->modify('+1 hour')),
        ]));

        $balances = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT date::text, closing_balance FROM money_account_daily_balance
             WHERE money_account_id = :accountId AND date >= :from ORDER BY date',
            ['accountId' => $this->accountId(), 'from' => $twoDaysAgo->format('Y-m-d')],
        );
        self::assertSame([
            $twoDaysAgo->format('Y-m-d') => '750.00',
            $twoDaysAgo->modify('+1 day')->format('Y-m-d') => '750.00',
            (new \DateTimeImmutable('today'))->format('Y-m-d') => '750.00',
        ], $balances);
    }

    public function testAccountIsFoundByNumberDigitsOnlyInOwnCompany(): void
    {
        $found = $this->facade->findStatementImportAccount($this->companyId(), '4070 2810 9000 0000 0001');

        self::assertNotNull($found);
        self::assertSame($this->accountId(), $found->moneyAccountId);
        self::assertSame('RUB', $found->currency);

        [$otherCompany] = $this->createCompanyWithAccount('40702810900000000003');
        $this->em->flush();
        self::assertNull($this->facade->findStatementImportAccount((string) $otherCompany->getId(), '40702810900000000001'));
    }

    public function testImportLogIsStartedAndFinishedWithSummary(): void
    {
        $importLogId = $this->facade->startStatementImportLog($this->companyId(), 'bank:alfa');
        $this->em->flush();

        $this->facade->finishStatementImportLog(
            $this->companyId(),
            $importLogId,
            new StatementImportSummary(2, 1, 5, 3, 1, \RuntimeException::class),
        );
        $this->em->flush();
        $this->em->clear();

        $log = $this->em->find(ImportLog::class, $importLogId);
        self::assertInstanceOf(ImportLog::class, $log);
        self::assertSame('bank:alfa', $log->getSource());
        self::assertSame([5, 3, 2], [$log->getCreatedCount(), $log->getSkippedDuplicates(), $log->getErrorsCount()]);
        self::assertNotNull($log->getFinishedAt());
        self::assertSame(
            ['accounts_found' => 2, 'accounts_matched' => 1, 'transactions_created' => 5, 'transactions_skipped_duplicates' => 3, 'error' => \RuntimeException::class],
            json_decode((string) $log->getFileName(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testImportLogOfAnotherCompanyIsNotFinished(): void
    {
        [$otherCompany] = $this->createCompanyWithAccount('40702810900000000004');
        $this->em->flush();
        $importLogId = $this->facade->startStatementImportLog((string) $otherCompany->getId(), 'bank:alfa');
        $this->em->flush();

        $this->expectException(StatementImportTargetNotFoundException::class);

        $this->facade->finishStatementImportLog($this->companyId(), $importLogId, new StatementImportSummary(0, 0, 0, 0, 0));
    }

    public function testCallOutsideTransactionIsRejected(): void
    {
        $this->expectException(StatementImportOutsideTransactionException::class);

        $this->facade->importBankStatementDay($this->companyId(), $this->accountId(), self::SOURCE, $this->day([
            $this->line('uuid-1', true, '10.00'),
        ]));
    }

    private function importDay(string $moneyAccountId, string $source, BankStatementDayInput $day): BankStatementDayResult
    {
        return $this->em->wrapInTransaction(
            fn (): BankStatementDayResult => $this->facade->importBankStatementDay($this->companyId(), $moneyAccountId, $source, $day),
        );
    }

    /**
     * @return array{Company, MoneyAccount}
     */
    private function createCompanyWithAccount(string $accountNumber): array
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-day-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withCurrency('RUB')
            ->build();
        $account->setAccountNumber($accountNumber);
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter(
            $companyId,
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($project);
        $this->em->persist($center);
        $this->em->persist(new FinancialResponsibilityCenterProject($companyId, $project, $center));

        return [$company, $account];
    }

    /**
     * @param list<BankStatementLineInput> $lines
     */
    private function day(array $lines): BankStatementDayInput
    {
        return new BankStatementDayInput(new \DateTimeImmutable('today'), $lines);
    }

    private function line(
        string $externalId,
        bool $isInflow,
        string $amount,
        ?string $currency = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): BankStatementLineInput {
        return new BankStatementLineInput(
            $externalId,
            $isInflow,
            $amount,
            $currency,
            $occurredAt ?? new \DateTimeImmutable('today 09:00'),
            'Оплата '.$externalId,
        );
    }

    private function countTransactions(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE company_id = :companyId',
            ['companyId' => $this->companyId()],
        );
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }

    private function accountId(): string
    {
        return (string) $this->account->getId();
    }
}
