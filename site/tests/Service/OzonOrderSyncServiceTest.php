<?php

namespace App\Tests\Service;

use App\Marketplace\Ozon\Adapter\OzonApiClient;
use App\Entity\Company;
use App\Marketplace\Ozon\Entity\OzonOrder;
use App\Marketplace\Ozon\Entity\OzonOrderItem;
use App\Marketplace\Ozon\Entity\OzonOrderStatusHistory;
use App\Marketplace\Ozon\Entity\OzonProduct;
use App\Marketplace\Ozon\Entity\OzonSyncCursor;
use App\Entity\User;
use App\Marketplace\Ozon\Repository\OzonOrderRepository;
use App\Marketplace\Ozon\Repository\OzonProductRepository;
use App\Marketplace\Ozon\Repository\OzonSyncCursorRepository;
use App\Marketplace\Ozon\Service\OzonOrderSyncService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class SimpleManagerRegistry implements ManagerRegistry
{
    public function __construct(private EntityManager $em)
    {
    }

    public function getDefaultConnectionName()
    {
        return 'default';
    }

    public function getConnection($name = null)
    {
        return $this->em->getConnection();
    }

    public function getConnections()
    {
        return [$this->em->getConnection()];
    }

    public function getConnectionNames()
    {
        return ['default'];
    }

    public function getDefaultManagerName()
    {
        return 'default';
    }

    public function getManager($name = null)
    {
        return $this->em;
    }

    public function getManagers()
    {
        return ['default' => $this->em];
    }

    public function resetManager($name = null)
    {
        return $this->em;
    }

    public function getAliasNamespace($alias)
    {
        return 'App\\Entity';
    }

    public function getManagerNames()
    {
        return ['default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null)
    {
        return $this->em->getRepository($persistentObject);
    }

    public function getManagerForClass($class)
    {
        return $this->em;
    }
}

class OzonOrderSyncServiceTest extends TestCase
{
    private EntityManager $em;
    private OzonOrderSyncService $service;
    private OzonApiClient $client;
    private Company $company;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $conn = ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->em = EntityManager::create($conn, $config);
        $schemaTool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(Company::class),
            $this->em->getClassMetadata(OzonProduct::class),
            $this->em->getClassMetadata(OzonOrder::class),
            $this->em->getClassMetadata(OzonOrderItem::class),
            $this->em->getClassMetadata(OzonOrderStatusHistory::class),
            $this->em->getClassMetadata(OzonSyncCursor::class),
        ];
        $schemaTool->createSchema($classes);
        $registry = new SimpleManagerRegistry($this->em);
        $orderRepo = new OzonOrderRepository($registry);
        $productRepo = new OzonProductRepository($registry);
        $cursorRepo = new OzonSyncCursorRepository($registry);
        $this->client = $this->createMock(OzonApiClient::class);
        $this->service = new OzonOrderSyncService($this->client, $this->em, $orderRepo, $productRepo, $cursorRepo);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('a@a');
        $user->setPassword('pass');
        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setOzonSellerId('1');
        $this->company->setOzonApiKey('k');
        $product = new OzonProduct(Uuid::uuid4()->toString(), $this->company);
        $product->setOzonSku('123');
        $product->setManufacturerSku('OFF1');
        $product->setName('Test');
        $product->setPrice(100);
        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->em->persist($product);
        $this->em->flush();
    }

    public function testSyncFbsCreatesOrderAndHistory(): void
    {
        $listResponse = [
            'result' => [
                'postings' => [
                    [
                        'posting_number' => 'PN1',
                        'status' => 'awaiting_packaging',
                        'warehouse_id' => 1,
                        'delivery_method' => ['name' => 'DM'],
                        'payment_status' => 'paid',
                        'created_at' => '2025-01-01T00:00:00Z',
                        'in_process_at' => '2025-01-01T01:00:00Z',
                        'status_updated_at' => '2025-01-01T01:00:00Z',
                        'products' => [
                            ['sku' => 123, 'offer_id' => 'OFF1', 'quantity' => 1, 'price' => '100'],
                        ],
                    ],
                ],
                'has_next' => false,
            ],
        ];
        $this->client->expects($this->exactly(1))->method('getFbsPostingsList')->willReturn($listResponse);
        $this->client->expects($this->never())->method('getFbsPosting');

        $result = $this->service->syncFbs($this->company, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-02'));
        $this->assertSame(1, $result['orders']);
        $this->assertSame(1, $result['statusChanges']);

        $order = $this->em->getRepository(OzonOrder::class)->findOneBy(['postingNumber' => 'PN1']);
        $this->assertNotNull($order);
        $items = $this->em->getRepository(OzonOrderItem::class)->findBy(['order' => $order]);
        $this->assertCount(1, $items);
        $this->assertEquals('123', $items[0]->getSku());
        $this->assertNotNull($items[0]->getProduct());
        $history = $this->em->getRepository(OzonOrderStatusHistory::class)->findBy(['order' => $order]);
        $this->assertCount(1, $history);

        // run again with same data
        $this->client->expects($this->exactly(1))->method('getFbsPostingsList')->willReturn($listResponse);
        $this->client->expects($this->never())->method('getFbsPosting');
        $this->service->syncFbs($this->company, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-02'));
        $history = $this->em->getRepository(OzonOrderStatusHistory::class)->findBy(['order' => $order]);
        $this->assertCount(1, $history);
    }
}
