<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\User;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $usersData = [
            ['admin@app.ru', 'password', ['ROLE_ADMIN']],
            ['manager@app.ru', 'password', ['ROLE_MANAGER']],
            ['user@app.ru', 'password', ['ROLE_USER']],
        ];

        $owner = new User(id: Uuid::uuid4()->toString());
        $owner->setEmail('owner@app.ru');
        $owner->setRoles(['ROLE_ADMIN']);
        $owner->setPassword($this->passwordHasher->hashPassword($owner, 'password'));
        $manager->persist($owner);

        $company = new Company(id: Uuid::uuid4()->toString(), user: $owner);
        $company->setName('Вумджой ООО');
        $manager->persist($company);

        $counterparty = new Counterparty(
            id: Uuid::uuid4()->toString(),
            company: $company,
            name: 'Ozon OOO',
            type: CounterpartyType::LEGAL_ENTITY
        );
        $manager->persist($counterparty);

        $moneyAccount = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: 'Osnovnoy',
            currency: 'RUB'
        );
        $manager->persist($moneyAccount);

        $manager->flush();

        foreach ($usersData as [$email, $plainPassword, $roles]) {
            $user = new User(id: Uuid::uuid4()->toString());
            $user->setEmail($email);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $manager->persist($user);
        }

        $manager->flush();
    }
}
