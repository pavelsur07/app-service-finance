<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminLoginThrottlingTest extends WebTestCaseBase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testCorrectPasswordIsRejectedAfterFiveFailedAttempts(): void
    {
        $client = static::createClient();
        // Лимитеры входа (по логину+IP и по IP) лежат в общем Redis-пуле и переживают прогоны.
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());
        $email = 'admin-throttle-'.Uuid::uuid4()->toString().'@example.test';
        $this->seedAdmin($client, $email);

        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin($client, $email, 'wrong-password-'.$i);
        }
        $this->submitLogin($client, $email, self::PASSWORD);

        $client->request('GET', '/admin/users');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testCorrectPasswordLogsInWithoutPriorFailures(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());
        $email = 'admin-throttle-'.Uuid::uuid4()->toString().'@example.test';
        $this->seedAdmin($client, $email);

        $this->submitLogin($client, $email, self::PASSWORD);

        $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    private function seedAdmin(KernelBrowser $client, string $email): void
    {
        $admin = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail($email)
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));

        $em = $this->em();
        $em->persist($admin);
        $em->flush();
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/admin/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
    }

    private function uniqueClientIp(): string
    {
        return sprintf(
            '2001:db8:%x:%x:%x:%x::1',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
