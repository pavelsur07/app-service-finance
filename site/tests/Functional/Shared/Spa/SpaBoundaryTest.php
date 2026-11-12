<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Spa;

use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SpaBoundaryTest extends WebTestCaseBase
{
    #[DataProvider('realms')]
    public function testAnonymousBootstrapIsJsonAndNotCached(string $realm): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://'.$realm.'.vashfindir.ru/api/'.$realm.'/v1/auth/csrf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('cache-control'));
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($body['tokens']['login']);
        self::assertNotEmpty($body['tokens']['logout']);
        self::assertSame('account' === $realm, isset($body['tokens']['company_select']));
    }

    #[DataProvider('wrongBoundaries')]
    public function testWrongHostOrPrefixIsJson404(string $host, string $path): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://'.$host.$path);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"error":{"code":"not_found","message":"Не найдено."}}', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('realms')]
    public function testAnonymousLogoutNeverRedirectsToLegacyLogin(string $realm): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://'.$realm.'.vashfindir.ru/api/'.$realm.'/v1/auth/logout', server: ['HTTP_ORIGIN' => 'https://'.$realm.'.vashfindir.ru']);
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getResponse()->headers->has('location'));
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    /** @return iterable<array{string}> */
    public static function realms(): iterable
    {
        yield 'account' => ['account'];
        yield 'admin' => ['admin'];
    }

    /** @return iterable<array{string, string}> */
    public static function wrongBoundaries(): iterable
    {
        yield ['account.vashfindir.ru', '/api/admin/v1/auth/csrf'];
        yield ['admin.vashfindir.ru', '/api/account/v1/auth/csrf'];
        yield ['app.vashfindir.ru', '/api/account/v1/auth/csrf'];
        yield ['app.vashfindir.ru', '/api/admin/v1/auth/csrf'];
        yield ['evil.example', '/api/account/v1/auth/csrf'];
        yield ['account.vashfindir.ru', '/internal/health'];
        yield ['admin.vashfindir.ru', '/admin/users'];
        yield ['account.vashfindir.ru', '/api/external/v1/anything'];
    }
}
