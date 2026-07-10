<?php

namespace App\Tests\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ReportAccountBalancesStructuredControllerTest extends WebTestCase
{
    public function testStructuredReportUsesOpeningBalanceBeforeSelectedDayTransactions(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM '.CashTransaction::class.' t')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccountDailyBalance::class.' b')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccount::class.' a')->execute();
        $em->createQuery('DELETE FROM '.Company::class.' c')->execute();
        $em->createQuery('DELETE FROM '.User::class.' u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('structured-opening@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('StructuredCo');

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'ПАО СБЕРБАНК', 'RUB');
        $account->setOpeningBalance('209594.87');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));

        $balance = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('2026-01-01'),
            '209594.87',
            '0.00',
            '990.00',
            '208604.87',
            'RUB'
        );

        $outflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '990.00',
            'RUB',
            new \DateTimeImmutable('2026-01-01')
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($balance);
        $em->persist($outflow);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/finance/reports/account-balances-structured', [
            'from' => '2026-01-01',
            'to' => '2026-01-01',
        ]);

        self::assertResponseIsSuccessful();

        $rowText = $this->tableRowText($crawler, 'ПАО СБЕРБАНК');
        self::assertMatchesRegularExpression('/ПАО СБЕРБАНК RUB 209 594\\.87 0\\.00 990\\.00 208 604\\.87/', $rowText);
    }

    public function testStructuredReportShowsBalancesAndTotals(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM '.CashTransaction::class.' t')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccountDailyBalance::class.' b')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccount::class.' a')->execute();
        $em->createQuery('DELETE FROM '.Company::class.' c')->execute();
        $em->createQuery('DELETE FROM '.User::class.' u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('TestCo');

        $accountRub = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main RUB', 'RUB');
        $accountRub->setOpeningBalance('0');
        $accountRub->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $accountUsd = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Cash USD', 'USD');
        $accountUsd->setOpeningBalance('0');
        $accountUsd->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $balanceRubFrom = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountRub,
            new \DateTimeImmutable('2024-01-01'),
            '0',
            '100',
            '0',
            '100',
            'RUB'
        );
        $balanceRubTo = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountRub,
            new \DateTimeImmutable('2024-01-05'),
            '100',
            '60',
            '10',
            '150',
            'RUB'
        );

        $balanceUsdFrom = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountUsd,
            new \DateTimeImmutable('2024-01-02'),
            '0',
            '75',
            '0',
            '75',
            'USD'
        );
        $balanceUsdTo = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountUsd,
            new \DateTimeImmutable('2024-01-05'),
            '75',
            '20',
            '5',
            '90',
            'USD'
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($accountRub);
        $em->persist($accountUsd);
        $em->persist($balanceRubFrom);
        $em->persist($balanceRubTo);
        $em->persist($balanceUsdFrom);
        $em->persist($balanceUsdTo);

        $rubInflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $accountRub,
            CashDirection::INFLOW,
            '60',
            'RUB',
            new \DateTimeImmutable('2024-01-03')
        );
        $rubOutflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $accountRub,
            CashDirection::OUTFLOW,
            '10',
            'RUB',
            new \DateTimeImmutable('2024-01-04')
        );
        $usdInflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $accountUsd,
            CashDirection::INFLOW,
            '20',
            'USD',
            new \DateTimeImmutable('2024-01-03')
        );
        $usdOutflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $accountUsd,
            CashDirection::OUTFLOW,
            '5',
            'USD',
            new \DateTimeImmutable('2024-01-04')
        );

        $em->persist($rubInflow);
        $em->persist($rubOutflow);
        $em->persist($usdInflow);
        $em->persist($usdOutflow);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/finance/reports/account-balances-structured', [
            'from' => '2024-01-01',
            'to' => '2024-01-05',
        ]);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertNotFalse($content);

        self::assertStringContainsString('Остатки и счета (структура)', $content);
        self::assertStringNotContainsString('Сохранить текущий фильтр', $content);
        self::assertStringNotContainsString('Очистить фильтры', $content);
        self::assertStringNotContainsString('Кассы', $content);
        self::assertStringNotContainsString('Исключить выбранные', $content);
        self::assertStringNotContainsString('Скрывать нулевые строки', $content);
        self::assertStringContainsString('Валюта: RUB', $content);
        self::assertStringContainsString('Валюта: USD', $content);

        $mainRubRowText = $this->tableRowText($crawler, 'Main RUB');
        self::assertMatchesRegularExpression('/Main RUB RUB 0\\.00 60\\.00 10\\.00 150\\.00/', $mainRubRowText);

        $cashUsdRowText = $this->tableRowText($crawler, 'Cash USD');
        self::assertMatchesRegularExpression('/Cash USD USD 0\\.00 20\\.00 5\\.00 90\\.00/', $cashUsdRowText);
    }

    public function testStructuredReportShowsAllAccounts(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM '.CashTransaction::class.' t')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccountDailyBalance::class.' b')->execute();
        $em->createQuery('DELETE FROM '.MoneyAccount::class.' a')->execute();
        $em->createQuery('DELETE FROM '.Company::class.' c')->execute();
        $em->createQuery('DELETE FROM '.User::class.' u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('filter@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('TestCo');

        $accountRub = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Filtered RUB', 'RUB');
        $accountRub->setOpeningBalance('0');
        $accountRub->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $accountUsd = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Visible USD', 'USD');
        $accountUsd->setOpeningBalance('0');
        $accountUsd->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $accountZero = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Zero RUB', 'RUB');
        $accountZero->setOpeningBalance('0');
        $accountZero->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $balanceUsd = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountUsd,
            new \DateTimeImmutable('2024-01-05'),
            '50',
            '10',
            '0',
            '60',
            'USD'
        );
        $balanceZero = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $accountZero,
            new \DateTimeImmutable('2024-01-05'),
            '0',
            '0',
            '0',
            '0',
            'RUB'
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($accountRub);
        $em->persist($accountUsd);
        $em->persist($accountZero);
        $em->persist($balanceUsd);
        $em->persist($balanceZero);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/finance/reports/account-balances-structured', [
            'from' => '2024-01-01',
            'to' => '2024-01-05',
        ]);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertNotFalse($content);

        self::assertStringContainsString('Filtered RUB', $content);
        self::assertStringContainsString('Visible USD', $content);
        self::assertStringContainsString('Zero RUB', $content);
        self::assertStringContainsString('60.00', $content);
    }

    private function tableRowText(\Symfony\Component\DomCrawler\Crawler $crawler, string $needle): string
    {
        foreach ($crawler->filter('tbody tr') as $row) {
            if (str_contains($row->textContent, $needle)) {
                return preg_replace('/\s+/', ' ', trim($row->textContent)) ?? '';
            }
        }

        self::fail(sprintf('Table row containing "%s" was not found.', $needle));
    }
}
