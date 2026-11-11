<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\SaveMoneyAccountAction;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class MoneyAccountSaveConcurrencyTest extends PostgresResetTestCase
{
    public function testRunningRecalculationDoesNotDeadlockWithWaitingSave(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build()->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        foreach ([$owner, $company, $account] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $name = 'cash_balance_race_'.Uuid::uuid7();
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/Cash/recalculate-account.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), ['CASH_RACE_NAME' => $name, 'CASH_RACE_COMPANY' => $company->getId(), 'CASH_RACE_ACCOUNT' => (string) $account->getId(), 'CASH_RACE_OPERATION' => 'save'], null, 90);
        $this->em->beginTransaction();
        try {
            self::getContainer()->get(\App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository::class)->acquireRecalculationLock($account);
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $this->connection->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$name]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting);
            $this->connection->executeStatement("SET LOCAL lock_timeout = '500ms'");
            try {
                self::getContainer()->get(\App\Cash\Service\Accounts\AccountBalanceService::class)->recalculateDailyRange($company, $account, new \DateTimeImmutable('today'), new \DateTimeImmutable('today'));
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
                self::fail('A waiting save must not hold the account row before the recalculation lock.');
            }
            $this->em->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->em->refresh($account);
            self::assertSame('234.56', $account->getOpeningBalance());
            self::assertSame('234.56', $account->getCurrentBalance());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function testConcurrentRecalculationCannotPublishAnOldOpeningBalance(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build()->setOpeningBalance('0.00')->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        foreach ([$owner, $company, $account] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $name = 'cash_balance_race_'.Uuid::uuid7();
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/Cash/recalculate-account.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), ['CASH_RACE_NAME' => $name, 'CASH_RACE_COMPANY' => $company->getId(), 'CASH_RACE_ACCOUNT' => (string) $account->getId()], null, 90);
        $this->em->beginTransaction();
        try {
            $account->setOpeningBalance('123.45');
            self::getContainer()->get(SaveMoneyAccountAction::class)($account);
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $this->connection->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$name]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Concurrent recalc must wait for the saving scenario.');
            $this->em->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->em->refresh($account);
            self::assertSame('123.45', $account->getOpeningBalance());
            self::assertSame('123.45', $account->getCurrentBalance());
            self::assertSame('123.45', $this->connection->fetchOne('SELECT closing_balance FROM money_account_daily_balance WHERE money_account_id = :id AND date = CURRENT_DATE', ['id' => $account->getId()]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
