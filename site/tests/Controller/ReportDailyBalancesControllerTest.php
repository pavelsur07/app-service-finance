<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ReportDailyBalancesControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Clean existing data
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('TestCo');
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $balance = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('today'),
            '0', '0', '0', '100', 'RUB'
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($balance);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/finance/reports/daily-balances');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Дневные промежуточные итоги (по остаткам)');
    }
}
