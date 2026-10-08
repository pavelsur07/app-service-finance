<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SoftDeleteCompanyTransactionsCommandTest extends IntegrationTestCase
{
    public function testDryRunCountsWithoutChangingData(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Read-only режим', $tester->getDisplay());

        $this->em->clear();
        self::assertFalse($this->reload($transaction)->isDeleted(), 'Dry-run не должен помечать транзакции удалёнными.');
    }

    public function testExecuteSoftDeletesOnlyOwnCompany(): void
    {
        $company = $this->company();
        $other = $this->company();
        $own = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $foreign = $this->transaction($other, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--reason' => 'очистка импорта']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        $own = $this->reload($own);
        self::assertTrue($own->isDeleted());
        // Геттеров на deleted_by/delete_reason у сущности нет — аудит-поля проверяем
        // напрямую в БД, чтобы не расширять API сущности ради теста.
        $audit = $this->em->getConnection()->fetchAssociative(
            'SELECT deleted_by, delete_reason FROM cash_transaction WHERE id = :id',
            ['id' => $own->getId()],
        );
        self::assertSame('cli:app:cash:soft-delete-company-transactions', $audit['deleted_by']);
        self::assertSame('очистка импорта', $audit['delete_reason']);
        self::assertFalse($this->reload($foreign)->isDeleted(), 'Транзакции соседней компании не должны затрагиваться.');
    }

    public function testSecondRunIsNoOp(): void
    {
        $company = $this->company();
        $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $this->runCommand((string) $company->getId(), ['--execute' => true]);
        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Удалять нечего', $tester->getDisplay());
    }

    public function testLockedPeriodRejectsWholeOperation(): void
    {
        $company = $this->company();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-02-01'));
        $locked = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('закрытом периоде', $tester->getDisplay());

        $this->em->clear();
        self::assertFalse($this->reload($locked)->isDeleted(), 'Транзакция закрытого периода не должна быть удалена.');
    }

    public function testLockBeforeAllTransactionsDoesNotBlock(): void
    {
        $company = $this->company();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-01-01'));
        $transaction = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        self::assertTrue($this->reload($transaction)->isDeleted(), 'Замок раньше всех транзакций не должен блокировать операцию.');
    }

    public function testRestoreRoundtrip(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();

        $this->runCommand((string) $company->getId(), ['--execute' => true, '--reason' => 'ошибочный импорт']);
        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--restore' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        $transaction = $this->reload($transaction);
        self::assertFalse($transaction->isDeleted());
        $audit = $this->em->getConnection()->fetchAssociative(
            'SELECT deleted_by, delete_reason FROM cash_transaction WHERE id = :id',
            ['id' => $transaction->getId()],
        );
        self::assertNull($audit['deleted_by']);
        self::assertNull($audit['delete_reason']);
    }

    /**
     * Откат массового удаления не должен «воскрешать» операции, удалённые вручную
     * через UI (обычно это дубли импорта): иначе задваиваются ДДС и остатки.
     */
    public function testRestoreKeepsManuallyDeletedTransactions(): void
    {
        $company = $this->company();
        $massDeleted = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $deletedByUser = $this->transaction($company, new \DateTimeImmutable('2026-01-16'));
        $deletedByUser->markDeleted('user-1', 'дубль');
        $deletedWithoutActor = $this->transaction($company, new \DateTimeImmutable('2026-01-17'));
        $deletedWithoutActor->markDeleted(null);
        $this->em->flush();

        $this->runCommand((string) $company->getId(), ['--execute' => true]);
        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--restore' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->em->clear();
        self::assertFalse($this->reload($massDeleted)->isDeleted());
        self::assertTrue($this->reload($deletedByUser)->isDeleted(), 'Удалённая пользователем операция должна остаться удалённой.');
        self::assertTrue($this->reload($deletedWithoutActor)->isDeleted(), 'Удаление без автора — не массовое, restore его не трогает.');
        self::assertSame('user-1', $this->em->getConnection()->fetchOne(
            'SELECT deleted_by FROM cash_transaction WHERE id = :id',
            ['id' => $deletedByUser->getId()],
        ));
    }

    public function testRestoreDryRunCountsOnlyMassDeleted(): void
    {
        $company = $this->company();
        $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->transaction($company, new \DateTimeImmutable('2026-01-16'))->markDeleted('user-1');
        $this->transaction($company, new \DateTimeImmutable('2026-01-17'))->markDeleted('user-1');
        $this->em->flush();
        $this->runCommand((string) $company->getId(), ['--execute' => true]);

        $tester = $this->runCommand((string) $company->getId(), ['--restore' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = preg_replace('/\s+/u', ' ', $tester->getDisplay());
        self::assertStringContainsString('Будет восстановлено: 1 транзакций', (string) $display);
        self::assertStringContainsString('Удалены вручную, restore их не тронет: 2', (string) $display);
    }

    public function testManuallyDeletedRowInLockedPeriodDoesNotBlockRestore(): void
    {
        $company = $this->company();
        $this->transaction($company, new \DateTimeImmutable('2026-01-15'))->markDeleted('user-1');
        $massDeleted = $this->transaction($company, new \DateTimeImmutable('2026-03-01'));
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-02-01'));
        $this->em->flush();
        $this->runCommand((string) $company->getId(), ['--execute' => true]);

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--restore' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->em->clear();
        self::assertFalse($this->reload($massDeleted)->isDeleted());
    }

    public function testMassDeletedRowInLockedPeriodStillBlocksRestore(): void
    {
        $company = $this->company();
        $massDeleted = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $this->em->flush();
        $this->runCommand((string) $company->getId(), ['--execute' => true]);
        $company = $this->em->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-02-01'));
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--restore' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->em->clear();
        self::assertTrue($this->reload($massDeleted)->isDeleted());
    }

    public function testReasonWithRestoreEmitsWarning(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, new \DateTimeImmutable('2026-01-15'));
        $transaction->markDeleted(null, 'старая причина');
        $this->em->flush();

        $tester = $this->runCommand((string) $company->getId(), ['--execute' => true, '--restore' => true, '--reason' => 'игнорируемая']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('игнорируется', $tester->getDisplay());
    }

    public function testUnknownCompanyFails(): void
    {
        $tester = $this->runCommand(Uuid::uuid4()->toString(), ['--execute' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /** @param array<string, mixed> $options */
    private function runCommand(string $companyId, array $options = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cash:soft-delete-company-transactions'));
        $tester->execute(['companyId' => $companyId] + $options);

        return $tester;
    }

    private function reload(CashTransaction $transaction): CashTransaction
    {
        $found = $this->em->find(CashTransaction::class, $transaction->getId());
        \assert($found instanceof CashTransaction);

        return $found;
    }

    private function company(): Company
    {
        // Email уникален в БД, дефолт билдера один на всех — для второй компании
        // в одном тесте нужен свой адрес.
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function transaction(Company $company, \DateTimeImmutable $occurredAt): CashTransaction
    {
        // Счёт создаётся один на компанию: у money_account уникальны (company_id, name),
        // а билдер даёт всем одинаковое имя по умолчанию.
        $account = $this->em->getRepository(MoneyAccount::class)->findOneBy(['company' => $company]);
        if (null === $account) {
            $account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->build();
            $this->em->persist($account);
            $this->em->flush();
        }

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000.00',
            'RUB',
            $occurredAt,
        );
        $this->em->persist($transaction);

        return $transaction;
    }
}
