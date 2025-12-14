<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use App\Service\AccountBalanceProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccountBalanceProviderTest extends KernelTestCase
{
    public function testReturnsLatestClosingBalancePerAccountUpToDate(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $provider = $container->get(AccountBalanceProvider::class);

        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('balance@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BalanceCo');

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main',
            'RUB'
        );
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $anotherAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::CASH,
            'CashBox',
            'RUB'
        );
        $anotherAccount->setOpeningBalance('0');
        $anotherAccount->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($anotherAccount);

        $em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('2024-01-10'),
            '0',
            '0',
            '0',
            '150.00',
            'RUB'
        ));

        $em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('2024-01-05'),
            '0',
            '0',
            '0',
            '100.00',
            'RUB'
        ));

        $em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $anotherAccount,
            new \DateTimeImmutable('2024-01-08'),
            '0',
            '0',
            '0',
            '50.00',
            'RUB'
        ));

        $em->flush();

        $accountIds = [$account->getId(), $anotherAccount->getId()];

        $balancesBeforeFirst = $provider->getClosingBalancesUpToDate(
            $company,
            new \DateTimeImmutable('2024-01-06'),
            $accountIds
        );

        self::assertSame('100.00', $balancesBeforeFirst[$account->getId()] ?? null);
        self::assertSame('50.00', $balancesBeforeFirst[$anotherAccount->getId()] ?? null);

        $balancesAfterLast = $provider->getClosingBalancesUpToDate(
            $company,
            new \DateTimeImmutable('2024-01-15'),
            $accountIds
        );

        self::assertSame('150.00', $balancesAfterLast[$account->getId()] ?? null);
        self::assertSame('50.00', $balancesAfterLast[$anotherAccount->getId()] ?? null);
    }
}
