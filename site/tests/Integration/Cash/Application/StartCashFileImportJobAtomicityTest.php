<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\StartCashFileImportJobAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

final class StartCashFileImportJobAtomicityTest extends IntegrationTestCase
{
    public function testFailedJobInsertWithoutConfirmationRollsBackImportLog(): void
    {
        [$company, $account] = $this->context();
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.reject_cash_job() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test job failure'; END; $$ LANGUAGE plpgsql");
        $this->connection->executeStatement('CREATE TRIGGER reject_cash_job BEFORE INSERT ON cash_file_import_jobs FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_cash_job()');
        try {
            self::getContainer()->get(StartCashFileImportJobAction::class)($company, $account, 'cash:file', 'a.csv', 'hash', ['date' => 'Date', 'amount' => 'Amount'], [], null);
            self::fail('A failed job insert must abort the entire scenario.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('test job failure', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM import_log WHERE company_id = :company', ['company' => $company->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_file_import_jobs WHERE company_id = :company', ['company' => $company->getId()]));
    }

    public function testRepeatedConfirmationReturnsOneJobAndOneLog(): void
    {
        [$company, $account] = $this->context();
        $action = self::getContainer()->get(StartCashFileImportJobAction::class);
        $key = Uuid::uuid7()->toString();
        $first = $action($company, $account, 'cash:file', 'a.csv', 'hash', ['date' => 'Date', 'amount' => 'Amount'], [], null, $key);
        $second = $action($company, $account, 'cash:file', 'a.csv', 'hash', ['date' => 'Date', 'amount' => 'Amount'], [], null, $key);
        self::assertSame($first->getId(), $second->getId());
        self::assertNotNull($first->getImportLog());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM import_log WHERE company_id = :company', ['company' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_file_import_jobs WHERE company_id = :company', ['company' => $company->getId()]));
    }

    public function testJobCannotUseAnAccountFromAnotherCompany(): void
    {
        [$company, $account] = $this->context();
        $owner = $company->getUser();
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($other);
        $this->em->flush();
        $this->expectException(\App\Cash\Exception\CashImportAccountMismatchException::class);
        self::getContainer()->get(StartCashFileImportJobAction::class)($other, $account, 'cash:file', 'a.csv', 'hash', ['date' => 'Date', 'amount' => 'Amount'], [], null);
    }

    /** @return array{Company, MoneyAccount} */
    private function context(): array
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
        foreach ([$owner, $company, $account] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$company, $account];
    }
}
