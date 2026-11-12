<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Spa;

use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SpaAuthenticationTest extends WebTestCaseBase
{
    #[DataProvider('realms')]
    public function testLoginRotatesSessionAndLogoutRequiresCsrf(string $realm): void
    {
        $client = static::createClient();
        $email = $this->seedUser(['ROLE_ADMIN']);
        $tokens = $this->tokens($client, $realm);
        $before = $client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $this->login($client, $realm, $email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(204);
        self::assertNotSame($before, $client->getCookieJar()->get('MOCKSESSID')?->getValue());
        $tokens = $this->tokens($client, $realm);
        $this->post($client, $realm, 'logout', null, $tokens['login']);
        self::assertResponseStatusCodeSame(403);
        $this->post($client, $realm, 'logout', null, $tokens['logout']);
        self::assertResponseStatusCodeSame(204);
        $this->post($client, $realm, 'logout', null, $tokens['logout']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOrdinaryUserCannotAcquireAdminSession(): void
    {
        $client = static::createClient();
        $email = $this->seedUser(['ROLE_COMPANY_OWNER']);
        $tokens = $this->tokens($client, 'admin');
        $this->login($client, 'admin', $email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(403);
        $this->post($client, 'admin', 'logout', null, $tokens['logout']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSuperAdminHierarchyAllowsLogin(): void
    {
        $client = static::createClient();
        $email = $this->seedUser(['ROLE_SUPER_ADMIN']);
        $tokens = $this->tokens($client, 'admin');
        $this->login($client, 'admin', $email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testInvalidCredentialsAreIndistinguishableAndThrottled(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', '2001:db8::'.random_int(1, 65535));
        $email = $this->seedUser([]);
        $tokens = $this->tokens($client, 'account');
        $this->login($client, 'account', 'missing-'.$email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(401);
        $missing = $client->getResponse()->getContent();
        for ($i = 0; $i < 5; ++$i) {
            $this->login($client, 'account', $email, 'wrong', $tokens['login']);
            self::assertResponseStatusCodeSame(401);
            self::assertSame($missing, $client->getResponse()->getContent());
        }
        $this->login($client, 'account', $email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('retry-after'));
    }

    public function testCsrfAndOriginCannotBeSubstituted(): void
    {
        $client = static::createClient();
        $email = $this->seedUser([]);
        $accountTokens = $this->tokens($client, 'account');
        $adminTokens = $this->tokens($client, 'admin');
        $this->login($client, 'account', $email, 'password', $adminTokens['login']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', ['email' => $email, 'password' => 'password'], [
            'HTTP_X_CSRF_TOKEN' => $accountTokens['login'],
            'HTTP_ORIGIN' => 'https://admin.vashfindir.ru',
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->getHistory()->clear(); // BrowserKit otherwise supplies the previous URL as Referer.
        $client->jsonRequest('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', ['email' => $email, 'password' => 'password'], ['HTTP_X_CSRF_TOKEN' => $accountTokens['login']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLoginPayloadAndMethodErrorsAreJson(): void
    {
        $client = static::createClient();
        $tokens = $this->tokens($client, 'account');
        $server = ['HTTP_ORIGIN' => 'https://account.vashfindir.ru', 'HTTP_X_CSRF_TOKEN' => $tokens['login'], 'CONTENT_TYPE' => 'application/json'];
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', server: $server, content: '{');
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', server: $server, content: '{"email":"invalid"}');
        self::assertResponseStatusCodeSame(422);
        $server['CONTENT_TYPE'] = 'text/plain';
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', server: $server, content: '{}');
        self::assertResponseStatusCodeSame(415);
        $client->request('GET', 'https://account.vashfindir.ru/api/account/v1/auth/login');
        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testExpectedAuthFailuresDoNotLogErrors(): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/logout', server: ['HTTP_ORIGIN' => 'https://account.vashfindir.ru']);
        self::assertResponseStatusCodeSame(401);
        $this->assertNoErrorLogs();
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', server: ['HTTP_ORIGIN' => 'https://admin.vashfindir.ru']);
        self::assertResponseStatusCodeSame(403);
        $this->assertNoErrorLogs();
        $client->request('POST', 'https://account.vashfindir.ru/api/account/v1/auth/login', server: ['HTTP_ORIGIN' => 'https://account.vashfindir.ru', 'CONTENT_TYPE' => 'text/plain'], content: '{}');
        self::assertResponseStatusCodeSame(415);
        $this->assertNoErrorLogs();
    }

    public function testRevokedAdminRoleInvalidatesExistingSession(): void
    {
        $client = static::createClient();
        $email = $this->seedUser(['ROLE_ADMIN']);
        $tokens = $this->tokens($client, 'admin');
        $this->login($client, 'admin', $email, 'password', $tokens['login']);
        self::assertResponseStatusCodeSame(204);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $user->setRoles([]);
        $this->em()->flush();
        $this->post($client, 'admin', 'logout', null, $tokens['logout']);
        self::assertResponseStatusCodeSame(401);
    }

    /** @return iterable<array{string}> */
    public static function realms(): iterable
    {
        yield ['account'];
        yield ['admin'];
    }

    /** @param list<string> $roles */
    private function seedUser(array $roles): string
    {
        $email = Uuid::uuid7()->toString().'@example.test';
        $user = (new User(Uuid::uuid7()->toString()))->setEmail($email)->setRoles($roles);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $email;
    }

    private function assertNoErrorLogs(): void
    {
        $handler = static::getContainer()->get(TestHandler::class);
        self::assertNotEmpty($handler->getRecords());
        self::assertSame([], array_filter($handler->getRecords(), static fn (LogRecord $record): bool => $record->level->value >= Level::Error->value));
    }

    /** @return array<string, string> */
    private function tokens(KernelBrowser $client, string $realm): array
    {
        $client->request('GET', 'https://'.$realm.'.vashfindir.ru/api/'.$realm.'/v1/auth/csrf');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['tokens'];
    }

    private function login(KernelBrowser $client, string $realm, string $email, string $password, string $csrf): void
    {
        $this->post($client, $realm, 'login', ['email' => $email, 'password' => $password], $csrf);
    }

    /** @param array<string, string>|null $body */
    private function post(KernelBrowser $client, string $realm, string $action, ?array $body, string $csrf): void
    {
        $origin = 'https://'.$realm.'.vashfindir.ru';
        $server = ['HTTP_ORIGIN' => $origin, 'HTTP_X_CSRF_TOKEN' => $csrf];
        if (null === $body) {
            $client->request('POST', $origin.'/api/'.$realm.'/v1/auth/'.$action, server: $server);
        } else {
            $client->jsonRequest('POST', $origin.'/api/'.$realm.'/v1/auth/'.$action, $body, $server);
        }
    }
}
