<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\User;
use App\Company\Message\SendPasswordChangedEmailMessage;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfilePasswordChangeTest extends WebTestCaseBase
{
    private const CURRENT_PASSWORD = 'current-password-123';
    private const NEW_PASSWORD = 'new-password-456';

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/profile/password');

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testUserCanChangePassword(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = $this->createUserWithPassword($client);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/password');

        self::assertResponseIsSuccessful();

        $sessionIdBefore = $client->getCookieJar()->get('MOCKSESSID')?->getValue();

        $form = $crawler->selectButton('Изменить пароль')->form([
            'change_password_form[currentPassword]' => self::CURRENT_PASSWORD,
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
            'change_password_form[website]' => '',
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect('/profile/password'));

        // Session::migrate(true): в ответе приходит новый session id
        $responseSessionIds = array_map(
            static fn (Cookie $cookie): string => $cookie->getValue(),
            array_filter(
                $client->getResponse()->headers->getCookies(),
                static fn (Cookie $cookie): bool => 'MOCKSESSID' === $cookie->getName(),
            ),
        );
        self::assertNotNull($sessionIdBefore);
        self::assertNotEmpty($responseSessionIds);
        self::assertNotContains($sessionIdBefore, $responseSessionIds, 'Session id не изменился после смены пароля');

        // Уведомление ушло в транспорт (в test — in-memory; читаем до перезагрузки kernel)
        $sent = $client->getContainer()->get('messenger.transport.async_sync')->getSent();
        self::assertCount(1, $sent);
        $notification = $sent[0]->getMessage();
        self::assertInstanceOf(SendPasswordChangedEmailMessage::class, $notification);
        self::assertSame($user->getId(), $notification->userId);

        // Сессия сохранилась: redirect ведёт на страницу, а не на логин
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $updatedUser = $this->reloadUser((string) $user->getId());

        self::assertTrue($this->hasher($client)->isPasswordValid($updatedUser, self::NEW_PASSWORD));
        self::assertFalse($this->hasher($client)->isPasswordValid($updatedUser, self::CURRENT_PASSWORD));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = $this->createUserWithPassword($client);

        $client->loginUser($user);
        $this->submitChangePasswordForm($client, 'wrong-password', self::NEW_PASSWORD);

        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('Текущий пароль указан неверно', (string) $client->getResponse()->getContent());

        $updatedUser = $this->reloadUser((string) $user->getId());

        self::assertTrue($this->hasher($client)->isPasswordValid($updatedUser, self::CURRENT_PASSWORD));
    }

    public function testRateLimitBlocksRepeatedAttempts(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = $this->createUserWithPassword($client);

        $client->loginUser($user);

        // Лимит: 5 попыток / 15 минут на аккаунт (ключ без IP)
        for ($i = 0; $i < 5; ++$i) {
            $this->submitChangePasswordForm($client, 'wrong-password-'.$i, self::NEW_PASSWORD);
            self::assertResponseIsUnprocessable();
        }

        $this->submitChangePasswordForm($client, 'wrong-password-5', self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Слишком много попыток', (string) $client->getResponse()->getContent());

        $updatedUser = $this->reloadUser((string) $user->getId());

        self::assertTrue($this->hasher($client)->isPasswordValid($updatedUser, self::CURRENT_PASSWORD));
    }

    public function testHoneypotSubmissionIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = $this->createUserWithPassword($client);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/password');

        $form = $crawler->selectButton('Изменить пароль')->form([
            'change_password_form[currentPassword]' => self::CURRENT_PASSWORD,
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
            'change_password_form[website]' => 'spam-bot-filled-this',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('Не удалось изменить пароль', (string) $client->getResponse()->getContent());

        $updatedUser = $this->reloadUser((string) $user->getId());

        self::assertTrue($this->hasher($client)->isPasswordValid($updatedUser, self::CURRENT_PASSWORD));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = $this->createUserWithPassword($client);

        $client->loginUser($user);
        $client->request('POST', '/profile/password', [
            'change_password_form' => [
                'currentPassword' => self::CURRENT_PASSWORD,
                'plainPassword' => ['first' => self::NEW_PASSWORD, 'second' => self::NEW_PASSWORD],
                'website' => '',
                '_token' => 'garbage-token',
            ],
        ]);

        self::assertResponseIsUnprocessable();

        $updatedUser = $this->reloadUser((string) $user->getId());

        self::assertTrue($this->hasher($client)->isPasswordValid($updatedUser, self::CURRENT_PASSWORD));
    }

    private function submitChangePasswordForm(KernelBrowser $client, string $currentPassword, string $newPassword): void
    {
        $crawler = $client->request('GET', '/profile/password');

        $form = $crawler->selectButton('Изменить пароль')->form([
            'change_password_form[currentPassword]' => $currentPassword,
            'change_password_form[plainPassword][first]' => $newPassword,
            'change_password_form[plainPassword][second]' => $newPassword,
            'change_password_form[website]' => '',
        ]);
        $client->submit($form);
    }

    private function reloadUser(string $userId): User
    {
        $em = $this->em();
        $em->clear();
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($user);

        return $user;
    }

    private function hasher(KernelBrowser $client): UserPasswordHasherInterface
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher;
    }

    private function createUserWithPassword(KernelBrowser $client): User
    {
        // Случайный id: ключ rate limiter'а привязан к аккаунту, тест не должен
        // зависеть от состояния Redis, оставшегося от прошлых прогонов
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4()->toString().'@example.test')
            ->build();
        $user->setPassword($this->hasher($client)->hashPassword($user, self::CURRENT_PASSWORD));

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
