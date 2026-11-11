<?php

declare(strict_types=1);

namespace App\Tests\Support\Spa;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class SpaWebTestCase extends WebTestCaseBase
{
    /** @param list<string> $roles */
    protected function user(array $roles = []): User
    {
        $user = (new User(Uuid::uuid7()->toString()))->setEmail(Uuid::uuid7()->toString().'@example.test')->setRoles($roles);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'spa-test-password'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function company(User $owner, string $name): Company
    {
        $company = (new Company(Uuid::uuid7()->toString(), $owner))->setName($name);
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    /** @return array<string, string> */
    protected function login(KernelBrowser $client, User $user, string $realm = 'account'): array
    {
        $root = 'https://'.$realm.'.vashfindir.ru';
        $client->request('GET', $root.'/api/'.$realm.'/v1/auth/csrf');
        self::assertResponseIsSuccessful();
        $csrf = $this->body($client)['tokens']['login'];
        $client->jsonRequest('POST', $root.'/api/'.$realm.'/v1/auth/login', ['email' => $user->getEmail(), 'password' => 'spa-test-password'], ['HTTP_ORIGIN' => $root, 'HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', $root.'/api/'.$realm.'/v1/auth/csrf');

        return $this->body($client)['tokens'];
    }

    /** @return array<string, mixed> */
    protected function body(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    protected function get(KernelBrowser $client, string $path, string $realm = 'account'): void
    {
        $client->request('GET', 'https://'.$realm.'.vashfindir.ru/api/'.$realm.'/v1/'.$path);
    }
}
