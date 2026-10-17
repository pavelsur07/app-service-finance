<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Controller\Internal;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Firewall `internal_api`: значения из `.env.test` —
 * токен `test-internal-token-0123456789abcdef`, allowlist `10.10.0.0/16,127.0.0.1`.
 */
final class InternalPingControllerTest extends WebTestCaseBase
{
    private const TOKEN = 'test-internal-token-0123456789abcdef';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testServiceWithTokenFromAllowedNetworkGetsPong(): void
    {
        $this->ping(['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'REMOTE_ADDR' => '10.10.3.7']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok'], $this->json());
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $this->ping(['REMOTE_ADDR' => '10.10.3.7']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('internal_auth_required', $this->json()['error']['code'] ?? null);
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        $this->ping(['HTTP_AUTHORIZATION' => 'Bearer wrong-token-0123456789abcdef-0000', 'REMOTE_ADDR' => '10.10.3.7']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('internal_auth_required', $this->json()['error']['code'] ?? null);
        self::assertStringNotContainsString('wrong-token', (string) $this->client->getResponse()->getContent());
    }

    public function testValidTokenFromForeignAddressIsForbidden(): void
    {
        $this->ping(['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'REMOTE_ADDR' => '203.0.113.5']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('internal_ip_forbidden', $this->json()['error']['code'] ?? null);
    }

    public function testRequestRelayedByPublicProxyIsJudgedByOriginalClient(): void
    {
        // traefik (приватный адрес, trusted proxy) пересылает запрос внешнего клиента.
        $this->ping([
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'REMOTE_ADDR' => '10.10.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('internal_ip_forbidden', $this->json()['error']['code'] ?? null);
    }

    public function testLoggedInUserSessionDoesNotOpenInternalApi(): void
    {
        $this->client->loginUser(UserBuilder::aUser()->withIndex(9871)->build(), 'main');

        $this->ping(['REMOTE_ADDR' => '10.10.3.7']);

        self::assertResponseStatusCodeSame(401);
    }

    /** @param array<string, string> $server */
    private function ping(array $server): void
    {
        $this->client->request('GET', '/internal/ping', server: $server);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
