<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        $usersData = [
            ['admin@app.ru', 'password', ['ROLE_ADMIN']],
            ['manager@app.ru', 'password', ['ROLE_MANAGER']],
            ['user@app.ru', 'password', ['ROLE_USER']],
        ];

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
