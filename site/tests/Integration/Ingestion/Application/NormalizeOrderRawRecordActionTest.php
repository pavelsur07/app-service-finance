<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\NormalizeOrderRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderItemRepository;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Tests\Integration\Ingestion\Fixtures\FakeOrderMapper;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class NormalizeOrderRawRecordActionTest extends IntegrationTestCase
{
    private string $companyId;
    private FakeOrderMapper $mapper;
    private NormalizeOrderRawRecordAction $action;
    private IngestOrderRepository $orders;
    private IngestOrderItemRepository $items;
    private IngestOrderStatusEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $this->mapper = self::getContainer()->get(FakeOrderMapper::class);
        $this->action = self::getContainer()->get(NormalizeOrderRawRecordAction::class);
        $this->orders = self::getContainer()->get(IngestOrderRepository::class);
        $this->items = self::getContainer()->get(IngestOrderItemRepository::class);
        $this->events = self::getContainer()->get(IngestOrderStatusEventRepository::class);
    }

    public function testCreatesOrderWithItemsAndOpeningStatusEvent(): void
    {
        $this->mapper->queue($this->order('delivering', 2));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
        self::assertCount(2, $this->items->findByOrderIndexedByLine($this->companyId, $order->getId()));
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Перенормализация того же сырья не должна плодить ни позиции, ни события.
     * Ключ идемпотентности позиций — (company, order, lineNo).
     */
    public function testRenormalizingSameRawCreatesNoDuplicates(): void
    {
        $this->mapper->queue($this->order('delivering', 2));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));
        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId, forceReplay: true));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, 'posting-1');
        self::assertNotNull($order);
        self::assertCount(2, $this->items->findByOrderIndexedByLine($this->companyId, $order->getId()));
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Часовой опрос присылает один и тот же статус снова и снова: событие
     * пишется только на смену, иначе журнал рос бы на 24 строки в сутки на
     * каждый заказ.
     */
    public function testStatusChangeAppendsSecondEvent(): void
    {
        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Устаревшее наблюдение — факт, и оно фиксируется. Но текущее состояние
     * заказа назад не едет: доставленный заказ не должен снова стать «в пути».
     */
    public function testStaleObservationIsRecordedButDoesNotRewindStatus(): void
    {
        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('-1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus(), 'Статус не должен ехать назад.');
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()), 'Наблюдение — факт, оно фиксируется.');
    }

    /**
     * Незнакомый статус деградирует в UNKNOWN и попадает в видимую очередь,
     * а не в NULL и не в исключение.
     */
    public function testUnknownStatusBecomesUnknownAndRaisesIssue(): void
    {
        $this->mapper->queue($this->order('какой_то_новый_статус', 1));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::UNKNOWN, $order->getStatus());

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'unknown_order_status'",
            ['c' => $this->companyId],
        ));
    }

    private function order(string $rawStatus, int $itemCount): MappedOrder
    {
        $items = [];
        for ($i = 0; $i < $itemCount; ++$i) {
            $items[] = new MappedOrderItem(
                lineNo: $i,
                quantity: 1,
                externalSku: '70000000'.$i,
                offerId: 'TEST-ART-'.$i,
                name: 'Товар '.$i,
                priceMinor: '150000',
                currency: 'RUB',
                sourceData: ['sku' => '70000000'.$i, 'offer_id' => 'TEST-ART-'.$i],
            );
        }

        return new MappedOrder(
            externalId: 'posting-1',
            scheme: IngestOrderScheme::FBO,
            orderedAt: new \DateTimeImmutable('-2 days'),
            rawStatus: $rawStatus,
            items: $items,
        );
    }

    private function storeRaw(string $externalId = 'page-1', ?\DateTimeImmutable $fetchedAt = null): string
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->storeAndGetIds(new RawBatch(
            companyId: $this->companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: FakeOrderMapper::RESOURCE_TYPE,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt ?? new \DateTimeImmutable(),
            rows: [['posting_number' => 'posting-1']],
        ))[0];
    }
}
