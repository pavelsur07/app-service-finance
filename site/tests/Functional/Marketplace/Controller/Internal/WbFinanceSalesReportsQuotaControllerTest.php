<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller\Internal;

use App\Marketplace\Wildberries\Application\Service\WbFinanceRateLimiter;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Общая квота WB finance sales-reports для wb-fetcher
 * (`services/_contracts/wb-finance-quota/v1`): слот и 429 идут в тот же бакет
 * `limiter.wb_finance` и Redis-cooldown, что у `SyncWbFinancialReportDayHandler`.
 * Ключ кабинета уникален в каждом тесте — состояние Redis прошлых прогонов не мешает.
 */
final class WbFinanceSalesReportsQuotaControllerTest extends WebTestCaseBase
{
    private const TOKEN = 'test-internal-token-0123456789abcdef';
    private const SERVICE_IP = '10.10.3.7';
    private const SLOT = '/internal/wb-finance/sales-reports/slot';
    private const RATE_LIMITED = '/internal/wb-finance/sales-reports/rate-limited';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testSecondSlotWithinWindowIsDenied(): void
    {
        $ref = Uuid::uuid7()->toString();

        $this->post(self::SLOT, ['connection_ref' => $ref]);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame(['granted' => true], $this->json());

        $this->post(self::SLOT, ['connection_ref' => $ref]);
        self::assertResponseStatusCodeSame(200);
        $denied = $this->json();
        self::assertFalse($denied['granted']);
        self::assertFalse($denied['shared_cooldown']);
        self::assertIsInt($denied['wait_seconds']);
        self::assertGreaterThanOrEqual(1, $denied['wait_seconds']);
        self::assertLessThanOrEqual(70, $denied['wait_seconds']);
    }

    public function testSlotSharesBucketWithSymfonyImport(): void
    {
        $ref = Uuid::uuid7()->toString();
        $limiter = static::getContainer()->get(WbFinanceRateLimiter::class);

        $this->post(self::SLOT, ['connection_ref' => $ref]);
        self::assertSame(['granted' => true], $this->json());

        // Ключ, которым берёт слот SyncWbFinancialReportDayHandler (бакет `connection:<id>`).
        $key = $limiter->buildSalesReportsRateLimitKeyForSellerBucket('connection:'.$ref);
        self::assertNotNull($limiter->tryConsume($key), 'импорт Symfony видит слот, взятый сервисом');
    }

    public function testRemote429SetsSharedCooldown(): void
    {
        $ref = Uuid::uuid7()->toString();

        $this->post(self::RATE_LIMITED, ['connection_ref' => $ref, 'retry_after_seconds' => 120]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cooldown_seconds' => 120], $this->json());

        $this->post(self::SLOT, ['connection_ref' => $ref]);
        $denied = $this->json();
        self::assertFalse($denied['granted']);
        self::assertTrue($denied['shared_cooldown']);
        self::assertGreaterThanOrEqual(110, $denied['wait_seconds']);

        $limiter = static::getContainer()->get(WbFinanceRateLimiter::class);
        self::assertNotNull($limiter->getActiveSalesReportsCooldownUntil('connection:'.$ref), 'cooldown виден импорту Symfony');
    }

    public function testRemote429WithoutPauseUsesDefault(): void
    {
        $this->post(self::RATE_LIMITED, ['connection_ref' => Uuid::uuid7()->toString(), 'retry_after_seconds' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cooldown_seconds' => 85], $this->json(), '70 с по умолчанию + 15 с запаса, как у импорта');
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testInvalidRequestIs422(string $uri, array|string $body): void
    {
        $this->post($uri, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_request', $this->json()['error']['code'] ?? null);
    }

    /** @return iterable<string, array{string, array<string, mixed>|string}> */
    public static function invalidRequests(): iterable
    {
        yield 'slot: не JSON' => [self::SLOT, 'not json'];
        yield 'slot: без connection_ref' => [self::SLOT, []];
        yield 'slot: connection_ref не UUID' => [self::SLOT, ['connection_ref' => 'wb-1']];
        yield '429: пауза строкой' => [self::RATE_LIMITED, ['connection_ref' => '01930000-0000-7000-8000-00000000c001', 'retry_after_seconds' => '60']];
        yield '429: пауза 0' => [self::RATE_LIMITED, ['connection_ref' => '01930000-0000-7000-8000-00000000c001', 'retry_after_seconds' => 0]];
        yield '429: пауза больше суток' => [self::RATE_LIMITED, ['connection_ref' => '01930000-0000-7000-8000-00000000c001', 'retry_after_seconds' => 86401]];
    }

    public function testWithoutTokenIs401(): void
    {
        $this->client->request('POST', self::SLOT, server: ['REMOTE_ADDR' => self::SERVICE_IP, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['connection_ref' => Uuid::uuid7()->toString()], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function post(string $uri, array|string $body): void
    {
        $this->client->request('POST', $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'REMOTE_ADDR' => self::SERVICE_IP,
            'CONTENT_TYPE' => 'application/json',
        ], content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
