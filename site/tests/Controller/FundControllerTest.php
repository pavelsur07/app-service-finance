<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\MoneyFund;
use App\Entity\MoneyFundMovement;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FundControllerTest extends WebTestCase
{
    public function testWidgetHiddenWhenFeatureDisabled(): void
    {
        self::ensureKernelShutdown();
        unset($_ENV['FEATURE_FUNDS_AND_WIDGET'], $_SERVER['FEATURE_FUNDS_AND_WIDGET']);

        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDatabase($em);

        $user = $this->createUser($hasher, 'disabled@example.com');
        $company = $this->createCompany($user, 'Disabled Co');
        $account = $this->createAccount($company, '100.00');
        $snapshot = $this->createDailyBalance($company, $account, '100.00');

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($snapshot);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/finance/reports/account-balances');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.money-totals-widget');
    }

    public function testWidgetVisibleWhenFeatureEnabled(): void
    {
        self::ensureKernelShutdown();
        $_ENV['FEATURE_FUNDS_AND_WIDGET'] = '1';
        $_SERVER['FEATURE_FUNDS_AND_WIDGET'] = '1';

        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDatabase($em);

        $user = $this->createUser($hasher, 'enabled@example.com');
        $company = $this->createCompany($user, 'Enabled Co');
        $account = $this->createAccount($company, '250.00');
        $snapshot = $this->createDailyBalance($company, $account, '250.00');
        $fund = new MoneyFund(Uuid::uuid4()->toString(), $company, 'Резерв', 'RUB');
        $movement = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $company,
            $fund,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            5000
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($snapshot);
        $em->persist($fund);
        $em->persist($movement);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/finance/reports/account-balances');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.money-totals-widget');

        unset($_ENV['FEATURE_FUNDS_AND_WIDGET'], $_SERVER['FEATURE_FUNDS_AND_WIDGET']);
    }

    private function resetDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\\Entity\\MoneyFundMovement m')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyFund f')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();
    }

    private function createUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        return $user;
    }

    private function createCompany(User $user, string $name): Company
    {
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        return $company;
    }

    private function createAccount(Company $company, string $balance): MoneyAccount
    {
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Основной', 'RUB');
        $account->setOpeningBalance('0.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $account->setCurrentBalance($balance);

        return $account;
    }

    private function createDailyBalance(Company $company, MoneyAccount $account, string $closing): MoneyAccountDailyBalance
    {
        return new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('today'),
            $closing,
            '0',
            '0',
            $closing,
            $account->getCurrency(),
        );
    }
}
