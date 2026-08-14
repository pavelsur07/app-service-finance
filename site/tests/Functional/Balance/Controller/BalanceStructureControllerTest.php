<?php

declare(strict_types=1);

namespace App\Tests\Functional\Balance\Controller;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BalanceStructureControllerTest extends WebTestCaseBase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('balance-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BalanceCo');

        $category = new BalanceCategory(Uuid::uuid4()->toString(), $company->getId());
        $category->setName('Активы');
        $category->setType(BalanceCategoryType::ASSET);

        $em->persist($user);
        $em->persist($company);
        $em->persist($category);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/balance/structure/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Настройка структуры баланса');
    }
}
