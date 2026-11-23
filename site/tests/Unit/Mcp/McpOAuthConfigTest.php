<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Security\McpOAuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpOAuthConfigTest extends TestCase
{
    public function testHttpsAndLoopbackRegistrationsCanCoexist(): void
    {
        $web = 'https://hermes.example.test:8443/api/mcp/oauth/callback/vashfindir';
        $loopback = 'http://localhost:48767/callback';
        $config = new McpOAuthConfig(null, null, null, null, json_encode([
            'hermes-web' => [$web],
            'hermes' => [$loopback],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([$web], $config->redirectsFor('hermes-web'));
        self::assertSame([$loopback], $config->redirectsFor('hermes'));
        self::assertSame([], $config->redirectsFor('unknown'));
    }

    #[DataProvider('invalidRedirects')]
    public function testUnsafeRedirectConfigurationIsRejected(string $redirect): void
    {
        $config = new McpOAuthConfig(null, null, null, null, json_encode([
            'hermes' => ['http://localhost:48767/callback'],
            'hermes-web' => [$redirect],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $config->redirectsFor('hermes-web'));
        self::assertSame([], $config->redirectsFor('hermes'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRedirects(): iterable
    {
        yield 'public HTTP' => ['http://hermes.example.test/callback'];
        yield 'HTTPS without path' => ['https://hermes.example.test'];
        yield 'wildcard' => ['https://*.example.test/callback'];
        yield 'user info' => ['https://hermes.example.test@foreign.example.test/callback'];
        yield 'query' => ['https://hermes.example.test/callback?next=https://foreign.example.test'];
        yield 'fragment' => ['https://hermes.example.test/callback#token'];
        yield 'relative path segment' => ['https://hermes.example.test/another/../callback'];
        yield 'encoded slash' => ['https://hermes.example.test/callback%2fother'];
    }
}
