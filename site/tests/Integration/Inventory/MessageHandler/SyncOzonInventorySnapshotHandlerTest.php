<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\MessageHandler;

use App\Company\Entity\Company;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\SyncOzonInventorySnapshotMessage;
use App\Inventory\MessageHandler\SyncOzonInventorySnapshotHandler;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncOzonInventorySnapshotHandlerTest extends IntegrationTestCase
{
    public function testSessionNotFoundNoOp(): void
    {
        $company = $this->createCompany(401);
        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);

        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000401', '88888888-8888-8888-8888-000000000401', 'manual'));

        self::assertSame(0, $this->countRawSnapshots());
    }

    public function testTerminalSessionNoOp(): void
    {
        $company = $this->createCompany(402);
        $session = $this->createSession($company);
        $session->markInProgress();
        $session->markCompleted();
        $this->em->flush();

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000402', $session->getId(), 'manual'));

        self::assertSame(0, $this->countRawSnapshots());
        self::assertSame(0, $this->countNormalizeMessages($session->getId(), $company->getId()));
    }

    public function testNoCredentialsMarksFailed(): void
    {
        $company = $this->createCompany(403);
        $session = $this->createSession($company);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000403', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertStringContainsString('credentials', (string) $session->getErrorMessage());
    }

    public function testSuccessOnePageSavesRawAndCompleted(): void
    {
        $company = $this->createCompany(404);
        $session = $this->createSession($company);
        $this->createSellerConnection($company, 404);
        $this->swapHttpClient([new MockResponse('{"result":{"items":[{"sku":"1"}]}}', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000404', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertSame(1, $session->getReceivedPages());

        $raw = $this->findRawBySession($session->getId());
        self::assertCount(1, $raw);
        self::assertSame('77777777-7777-7777-7777-000000000404', $raw[0]->getRequestParams()['connectionId']);

        $columns = $this->em->getConnection()
            ->createSchemaManager()
            ->listTableColumns('inventory_raw_snapshots');
        self::assertArrayNotHasKey('connection_id', $columns);

        self::assertGreaterThan(0, $this->countNormalizeMessages($session->getId(), $company->getId()));
    }

    public function testSuccessSeveralPagesSavesSeveralRawSnapshots(): void
    {
        $company = $this->createCompany(405);
        $session = $this->createSession($company);
        $this->createSellerConnection($company, 405);
        $this->swapHttpClient([
            new MockResponse('{"result":{"items":[{"sku":"1"}],"last_id":"next-1"}}', ['http_code' => 200]),
            new MockResponse('{"result":{"items":[{"sku":"2"}]}}', ['http_code' => 200]),
        ]);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000405', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertCount(2, $this->findRawBySession($session->getId()));
    }

    public function testErrorBeforeFirstPageMarksFailedWithoutThrow(): void
    {
        $company = $this->createCompany(406);
        $session = $this->createSession($company);
        $this->createSellerConnection($company, 406);
        $this->swapHttpClient([new MockResponse('{"error":"server"}', ['http_code' => 500])]);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000406', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertStringContainsString('HTTP 500', (string) $session->getErrorMessage());
        self::assertSame(0, $this->countNormalizeMessages($session->getId(), $company->getId()));
    }

    public function testErrorAfterFirstPageMarksPartialWithoutThrow(): void
    {
        $company = $this->createCompany(407);
        $session = $this->createSession($company);
        $this->createSellerConnection($company, 407);
        $this->swapHttpClient([
            new MockResponse('{"result":{"items":[{"sku":"1"}],"last_id":"next-1"}}', ['http_code' => 200]),
            new MockResponse('{"error":"server"}', ['http_code' => 500]),
        ]);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000407', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Partial, $session->getStatus());
        self::assertCount(1, $this->findRawBySession($session->getId()));
        self::assertSame(0, $this->countNormalizeMessages($session->getId(), $company->getId()));
    }

    public function testRateLimitBeforeFirstPageMarksFailedWithoutThrow(): void
    {
        $company = $this->createCompany(408);
        $session = $this->createSession($company);
        $this->createSellerConnection($company, 408);
        $this->swapHttpClient([new MockResponse('{"error":"rate"}', ['http_code' => 429])]);

        $handler = self::getContainer()->get(SyncOzonInventorySnapshotHandler::class);
        $handler(new SyncOzonInventorySnapshotMessage($company->getId(), '77777777-7777-7777-7777-000000000408', $session->getId(), 'manual'));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertStringContainsString('Rate limit', (string) $session->getErrorMessage());
        self::assertSame(0, $this->countNormalizeMessages($session->getId(), $company->getId()));
    }

    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function createSession(Company $company): InventorySnapshotSession
    {
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    private function createSellerConnection(Company $company, int $suffix): void
    {
        $connection = new MarketplaceConnection(sprintf('77777777-7777-7777-7777-%012d', $suffix), $company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();
    }

    /** @param list<MockResponse> $responses */
    private function swapHttpClient(array $responses): void
    {
        self::getContainer()->set('http_client', new MockHttpClient($responses));
    }

    /** @return list<InventoryRawSnapshot> */
    private function findRawBySession(string $sessionId): array
    {
        return $this->em->getRepository(InventoryRawSnapshot::class)->findBy(['snapshotSessionId' => $sessionId], ['pageNumber' => 'ASC']);
    }

    private function countRawSnapshots(): int
    {
        return $this->em->getRepository(InventoryRawSnapshot::class)->count([]);
    }


    private function countNormalizeMessages(string $sessionId, string $companyId): int
    {
        $cnt = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE body LIKE :needle1 AND body LIKE :needle2 AND body LIKE :needle3",
            [
                'needle1' => '%NormalizeInventorySnapshotMessage%',
                'needle2' => '%"snapshotSessionId":"'.$sessionId.'"%',
                'needle3' => '%"companyId":"'.$companyId.'"%',
            ],
        );

        $body = (string) $this->em->getConnection()->fetchOne(
            "SELECT body FROM messenger_messages WHERE body LIKE :needle ORDER BY available_at DESC LIMIT 1",
            ['needle' => '%NormalizeInventorySnapshotMessage%'],
        );
        if ($body !== '') {
            self::assertStringContainsString('"source":"ozon"', $body);
            self::assertStringNotContainsString('api_key', $body);
            self::assertStringNotContainsString('client_id', $body);
        }

        return $cnt;
    }

}
