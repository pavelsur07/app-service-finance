<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Вывод команды попадает в логи и в отчёты, поэтому проверяется не только вердикт,
 * но и то, что в него не утекают обороты и полные идентификаторы прода.
 */
final class VerifyCashTransactionSplitsCommandTest extends IntegrationTestCase
{
    private ?MoneyAccount $account = null;

    public function testTotalsComparisonIsSkippedUntilBackfillCompletes(): void
    {
        $company = $this->company();
        $category = $this->category($company, 'Аренда');
        $this->transaction($company, '1000.00', $category);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Сверка итогов пропущена', $output);
        self::assertStringNotContainsString('по колонке', $output);
        self::assertStringNotContainsString('1000.00', $output, 'Обороты не должны попадать в вывод.');
        self::assertStringNotContainsString((string) $category->getId(), $output, 'Полные ID не должны попадать в вывод.');
    }

    public function testGreenRunWhenSplitsMirrorTheColumn(): void
    {
        $company = $this->company();
        $category = $this->category($company, 'Аренда');
        $transaction = $this->transaction($company, '1000.00', $category);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, '1000.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Сверка пройдена', $tester->getDisplay());
    }

    public function testTotalsMismatchIsReportedWithoutAmountsAndFullIds(): void
    {
        $company = $this->company();

        // Три транзакции, каждая разбита на две категории: колонка знает только первую,
        // поэтому расхождение получается по обеим — шесть групп, больше лимита выборки.
        $categories = [];
        for ($i = 0; $i < 3; ++$i) {
            $primary = $this->category($company, sprintf('Первая %d', $i));
            $secondary = $this->category($company, sprintf('Вторая %d', $i));
            $categories[] = $primary;
            $categories[] = $secondary;

            $transaction = $this->transaction($company, '1000.00', $primary);
            $transaction->replaceSplits([
                new CashTransactionSplit($transaction, $primary, '600.00', CashTransactionSplitSource::MANUAL),
                new CashTransactionSplit($transaction, $secondary, '400.00', CashTransactionSplitSource::MANUAL),
            ]);
        }
        $this->em->flush();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'Расхождение итогов обязано валить exit code.');
        self::assertStringContainsString('Групп с расхождением: 6', $output);
        self::assertStringContainsString('Показаны первые 5 из 6', $output);

        self::assertStringNotContainsString('600.00', $output, 'Суммы строк не должны выводиться.');
        self::assertStringNotContainsString('400.00', $output, 'Суммы строк не должны выводиться.');
        self::assertStringNotContainsString('1000.00', $output, 'Суммы транзакций не должны выводиться.');
        self::assertStringNotContainsString((string) $company->getId(), $output, 'Полный ID компании не должен выводиться.');

        foreach ($categories as $category) {
            self::assertStringNotContainsString((string) $category->getId(), $output, 'Полный ID категории не должен выводиться.');
        }

        // Усечённый префикс при этом остаётся — иначе находку нечем локализовать.
        self::assertStringContainsString(substr((string) $company->getId(), 0, 8), $output);
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cash:verify-transaction-splits'));
        $tester->execute([]);

        return $tester;
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function transaction(Company $company, string $amount, CashflowCategory $category): CashTransaction
    {
        // Счёт создаётся один на компанию: у money_account уникальны (company_id, name),
        // а билдер даёт всем одинаковое имя по умолчанию.
        if (null === $this->account) {
            $this->account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->build();
            $this->em->persist($this->account);
            $this->em->flush();
        }
        $account = $this->account;

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );
        $transaction->setCashflowCategory($category);
        $this->em->persist($transaction);

        return $transaction;
    }
}
