<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\SaveMoneyAccountAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

final class SaveMoneyAccountActionTest extends IntegrationTestCase
{
    public function testSaveRecalculatesAndSynchronizesManagedBalance(): void
    {
        $account = $this->account();
        $account->setOpeningBalance('123.45');
        self::getContainer()->get(SaveMoneyAccountAction::class)($account);
        self::assertSame('123.45', $account->getCurrentBalance());
        // A later flush must not overwrite DBAL's balance with an obsolete ORM snapshot.
        $account->setName('Renamed');
        $this->em->flush();
        $this->em->refresh($account);
        self::assertSame('123.45', $account->getCurrentBalance());
        self::assertSame('123.45', $this->connection->fetchOne('SELECT closing_balance FROM money_account_daily_balance WHERE money_account_id = :id AND date = CURRENT_DATE', ['id' => $account->getId()]));
    }

    public function testBalanceFailureRollsBackAccountAndSnapshots(): void
    {
        $account = $this->account();
        $this->em->persist($account);
        $this->em->flush();
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.reject_cash_balance() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test balance failure'; END; $$ LANGUAGE plpgsql");
        $this->connection->executeStatement('CREATE TRIGGER reject_cash_balance BEFORE UPDATE OF current_balance ON money_account FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_cash_balance()');
        $account->setOpeningBalance('123.45');
        try {
            self::getContainer()->get(SaveMoneyAccountAction::class)($account);
            self::fail('Recalculation failure must abort the complete save scenario.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('test balance failure', $exception->getMessage());
        }
        self::assertSame('0.00', $this->connection->fetchOne('SELECT opening_balance FROM money_account WHERE id = :id', ['id' => $account->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM money_account_daily_balance WHERE money_account_id = :id', ['id' => $account->getId()]));
    }

    public function testRecalculationUsesSavedParametersInsteadOfStaleEntity(): void
    {
        $account = $this->account();
        $this->em->persist($account);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE money_account SET opening_balance = :balance WHERE id = :id', ['balance' => '234.56', 'id' => $account->getId()]);
        self::getContainer()->get(\App\Cash\Service\Accounts\AccountBalanceService::class)->recalculateDailyRange($account->getCompany(), $account, new \DateTimeImmutable('today'), new \DateTimeImmutable('today'));
        self::assertSame('234.56', $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = :id', ['id' => $account->getId()]));
    }

    private function account(): MoneyAccount
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build()->setOpeningBalance('0.00')->setOpeningBalanceDate(new \DateTimeImmutable('today'));
    }
}
