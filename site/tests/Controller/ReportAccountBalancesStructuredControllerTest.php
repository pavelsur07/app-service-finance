<?php
namespace App\Tests\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\User;
use App\Enum\CashDirection;
use App\Enum\MoneyAccountType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ReportAccountBalancesStructuredControllerTest extends WebTestCase
{
    public function testStructuredReportShowsBalancesAndTotals(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM App\\Entity\\CashTransaction t')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

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
        $client->request('GET', '/finance/reports/account-balances-structured', [
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

        self::assertStringContainsString('Main RUB', $content);
        self::assertStringContainsString('100.00', $content);
        self::assertStringContainsString('150.00', $content);
        self::assertStringContainsString('60.00', $content);
        self::assertStringContainsString('10.00', $content);

        self::assertStringContainsString('Cash USD', $content);
        self::assertStringContainsString('75.00', $content);
        self::assertStringContainsString('90.00', $content);
        self::assertStringContainsString('20.00', $content);
        self::assertStringContainsString('5.00', $content);
    }

    public function testStructuredReportShowsAllAccounts(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM App\\Entity\\CashTransaction t')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

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
}
