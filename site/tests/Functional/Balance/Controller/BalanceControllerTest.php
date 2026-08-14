<?php

declare(strict_types=1);

namespace App\Tests\Functional\Balance\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BalanceControllerTest extends WebTestCaseBase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('balance-report@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BalanceReportCo');

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/balance/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Баланс');
    }
}
