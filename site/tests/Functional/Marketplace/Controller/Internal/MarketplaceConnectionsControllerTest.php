<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller\Internal;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceConnectionsSnapshotBuilder;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `GET /internal/marketplace-connections` — контракт
 * `services/_contracts/marketplace-connections/v1/schema.json`. Каталог
 * `services/` в контейнер тестов не монтируется, поэтому ключи схемы
 * повторены здесь; при новой версии контракта — обновить вместе.
 */
final class MarketplaceConnectionsControllerTest extends WebTestCaseBase
{
    private const TOKEN = 'test-internal-token-0123456789abcdef';
    private const SERVICE_IP = '10.10.3.7';

    private const SELLER_A = '01930000-0000-7000-8000-00000000a001';
    private const PERF_A = '01930000-0000-7000-8000-00000000a002';
    private const SELLER_INACTIVE = '01930000-0000-7000-8000-00000000b001';
    private const WB_SELLER = '01930000-0000-7000-8000-00000000c001';
    private const SELLER_LEGACY = '01930000-0000-7000-8000-00000000d001';
    private const SELLER_BROKEN = '01930000-0000-7000-8000-00000000e001';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testServesActiveOzonCabinetsByContract(): void
    {
        $companyA = $this->company(9881);
        $this->connection(self::SELLER_A, $companyA, MarketplaceConnectionType::SELLER, 'seller-client-a', 'seller-key-a', encrypted: true);
        $this->connection(self::PERF_A, $companyA, MarketplaceConnectionType::PERFORMANCE, 'perf-client-a', 'perf-secret-a', encrypted: true);
        $this->connection(self::SELLER_INACTIVE, $this->company(9882), MarketplaceConnectionType::SELLER, 'c', 'inactive-key', encrypted: true, active: false);
        $this->connection(self::WB_SELLER, $this->company(9883), MarketplaceConnectionType::SELLER, null, 'wb-key', encrypted: true, marketplace: MarketplaceType::WILDBERRIES);
        $legacyCompany = $this->company(9884);
        $this->connection(self::SELLER_LEGACY, $legacyCompany, MarketplaceConnectionType::SELLER, 'legacy-client', 'legacy-plain-key', encrypted: false);
        $this->em()->flush();
        $this->em()->clear();

        $this->get('/internal/marketplace-connections?provider=ozon');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $body = $this->json();
        self::assertSame(['schema_version', 'generated_at', 'connections'], array_keys($body));
        self::assertSame('1', $body['schema_version']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $body['generated_at']));

        $byRef = $this->byRef($body);
        self::assertArrayNotHasKey(self::SELLER_INACTIVE, $byRef);
        self::assertArrayNotHasKey(self::WB_SELLER, $byRef);
        self::assertArrayNotHasKey(self::PERF_A, $byRef, 'performance той же компании входит в кабинет seller');

        $cabinet = $byRef[self::SELLER_A];
        self::assertSame(['connection_ref', 'company_id', 'provider', 'updated_at', 'credentials', 'resource_types'], array_keys($cabinet));
        self::assertSame((string) $companyA->getId(), $cabinet['company_id']);
        self::assertSame('ozon', $cabinet['provider']);
        self::assertSame(['seller', 'performance'], array_keys($cabinet['credentials']));
        self::assertSame(['client_id' => 'seller-client-a', 'api_key' => 'seller-key-a'], $cabinet['credentials']['seller']);
        self::assertSame(['client_id' => 'perf-client-a', 'client_secret' => 'perf-secret-a'], $cabinet['credentials']['performance']);
        // Отправления — всем кабинетам с ключом seller (`ozon_fetcher.orders_companies: ['*']`).
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::ORDERS_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::PRODUCTS_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::PERFORMANCE_RESOURCE_TYPES], $cabinet['resource_types']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $cabinet['updated_at']));

        self::assertSame(['client_id' => 'legacy-client', 'api_key' => 'legacy-plain-key'], $byRef[self::SELLER_LEGACY]['credentials']['seller']);
        self::assertNull($byRef[self::SELLER_LEGACY]['credentials']['performance']);
    }

    public function testUndecryptableKeyDropsOnlyItsCabinet(): void
    {
        $this->connection(self::SELLER_A, $this->company(9885), MarketplaceConnectionType::SELLER, 'client-a', 'key-a', encrypted: true);
        $broken = $this->connection(self::SELLER_BROKEN, $this->company(9886), MarketplaceConnectionType::SELLER, 'client-e', 'key-e', encrypted: true);
        $broken->setEncryptedApiKey('not-a-ciphertext', 'v1');
        $broken->setApiKey('');
        $this->em()->flush();
        $this->em()->clear();

        $this->get('/internal/marketplace-connections');

        self::assertResponseStatusCodeSame(200);
        $byRef = $this->byRef($this->json());
        self::assertArrayHasKey(self::SELLER_A, $byRef);
        self::assertArrayNotHasKey(self::SELLER_BROKEN, $byRef);
    }

    public function testOtherProvidersAreNotServedInV1(): void
    {
        $this->get('/internal/marketplace-connections?provider=wildberries');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_provider', $this->json()['error']['code'] ?? null);
    }

    public function testWithoutTokenNothingIsServed(): void
    {
        $this->connection(self::SELLER_A, $this->company(9887), MarketplaceConnectionType::SELLER, 'client-a', 'secret-never-leaks', encrypted: true);
        $this->em()->flush();

        $this->client->request('GET', '/internal/marketplace-connections', server: ['REMOTE_ADDR' => self::SERVICE_IP]);

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString('secret-never-leaks', (string) $this->client->getResponse()->getContent());
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);

        return $company;
    }

    private function connection(
        string $id,
        Company $company,
        MarketplaceConnectionType $type,
        ?string $clientId,
        string $secret,
        bool $encrypted,
        bool $active = true,
        MarketplaceType $marketplace = MarketplaceType::OZON,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection($id, $company, $marketplace, $type);
        if ($encrypted) {
            static::getContainer()->get(ConnectionApiKeyCodec::class)->applyApiKey($connection, $secret);
        } else {
            $connection->setApiKey($secret);
        }
        $connection->setClientId($clientId)->setIsActive($active);
        $this->em()->persist($connection);

        return $connection;
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'REMOTE_ADDR' => self::SERVICE_IP]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, array<string, mixed>>
     */
    private function byRef(array $body): array
    {
        self::assertIsArray($body['connections']);
        $byRef = [];
        foreach ($body['connections'] as $cabinet) {
            self::assertIsArray($cabinet);
            $byRef[(string) $cabinet['connection_ref']] = $cabinet;
        }

        return $byRef;
    }
}
