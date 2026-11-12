<?php

declare(strict_types=1);

namespace App\Tests\Support\Spa;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

/** Fixtures for the disposable HTTP stack; never registered as an application service. */
final class SpaHttpFixtures
{
    public static function load(ObjectManager $em): void
    {
        foreach (['owner' => [], 'admin' => ['ROLE_ADMIN']] as $name => $roles) {
            $email = 'auth-'.$name.'@example.test';
            if (null !== $em->getRepository(User::class)->findOneBy(['email' => $email])) {
                continue;
            }
            $user = (new User(Uuid::uuid7()->toString()))->setEmail($email)->setRoles($roles)->setPassword(password_hash('Local-auth-password-123!', \PASSWORD_BCRYPT));
            $em->persist($user);
            foreach (['Первая тестовая компания', 'Вторая тестовая компания'] as $companyName) {
                $em->persist((new Company(Uuid::uuid7()->toString(), $user))->setName($companyName));
            }
        }
        $em->flush();
    }
}
