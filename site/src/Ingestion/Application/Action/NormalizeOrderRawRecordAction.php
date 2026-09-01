<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Domain\Service\OrderMapperRegistry;
use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Entity\IngestOrderItem;
use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderItemRepository;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Нормализация сырья заказов в IngestOrder / IngestOrderItem и журнал статусов.
 *
 * Отдельное действие от финансового {@see NormalizeRawRecordAction}, а не ветка
 * внутри него: то заточено под MappedTransaction и UpsertFinancialTransaction,
 * у него уже 13 зависимостей, и рост горячего финансового пути ради заказов не
 * оправдан. Маршрутизация — в NormalizeRawRecordHandler по наличию маппера в
 * OrderMapperRegistry.
 */
final readonly class NormalizeOrderRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
        private OrderMapperRegistry $orderMapperRegistry,
        private IngestOrderStatusMapper $statusMapper,
        private ListingResolverRegistry $listingResolverRegistry,
        private IngestOrderRepository $orderRepository,
        private IngestOrderItemRepository $itemRepository,
        private IngestOrderStatusEventRepository $statusEventRepository,
        private RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NormalizeRawRecordCommand $command): void
    {
        $rawRecord = $this->rawRecordRepository->findByIdAndCompany($command->rawRecordId, $command->companyId);
        if (null === $rawRecord) {
            throw new RawRecordNotFoundException('Raw record not found for requested company.');
        }

        $rows = iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $command->companyId), false);
        $mapper = $this->orderMapperRegistry->get($rawRecord->getSource(), $rawRecord->getResourceType());
        $batch = $mapper->map($rawRecord, $rows);
        $orders = $batch->orders;

        // Момент наблюдения — когда сырьё было СКАЧАНО, а не когда мы его
        // разбираем. Иначе повторная нормализация старого сырья задним числом
        // обогнала бы свежий статус и сломала монотонность.
        $observedAt = $rawRecord->getFetchedAt();

        foreach ($orders as $mappedOrder) {
            $this->applyOrder($rawRecord, $mappedOrder, $observedAt);
        }

        // Строка, которую маппер не смог разобрать, обязана стать видимой
        // очередью. Курсор после нормализации уже уехал вперёд, поэтому
        // молчаливый пропуск — постоянная потеря, ничем не отличимая от
        // «заказов в окне не было».
        foreach ($batch->skipped as $skipped) {
            ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                companyId: $command->companyId,
                rawRecordId: $rawRecord->getId(),
                operationGroupId: null,
                kind: NormalizationIssueKind::MAPPER_FAILURE,
                details: [
                    'source' => $rawRecord->getSource()->value,
                    'resourceType' => $rawRecord->getResourceType(),
                    'reason' => $skipped['reason'],
                    'externalId' => $skipped['hint'],
                ],
            ));
        }

        $rawRecord->markNormalizationDone();
        $this->entityManager->flush();

        $this->logger->info('Ingestion order raw record normalized.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $rawRecord->getId(),
            'resourceType' => $rawRecord->getResourceType(),
            'orders' => count($orders),
            'skippedRows' => count($batch->skipped),
        ]);
    }

    private function applyOrder(IngestRawRecord $rawRecord, MappedOrder $mapped, \DateTimeImmutable $observedAt): void
    {
        $companyId = $rawRecord->getCompanyId();
        $status = $this->statusMapper->map($rawRecord->getSource(), $mapped->scheme, $mapped->rawStatus);

        if (IngestOrderStatus::UNKNOWN === $status) {
            // Видимая очередь на разбор вместо тихой потери: заказ сохраняется,
            // но незнакомый токен становится заметен в существующем UI issues.
            ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                companyId: $companyId,
                rawRecordId: $rawRecord->getId(),
                operationGroupId: null,
                kind: NormalizationIssueKind::UNKNOWN_ORDER_STATUS,
                details: [
                    'source' => $rawRecord->getSource()->value,
                    'scheme' => $mapped->scheme->value,
                    'rawStatus' => $mapped->rawStatus,
                    'externalId' => $mapped->externalId,
                ],
            ));
        }

        $order = $this->orderRepository->findByExternalId(
            $companyId,
            $rawRecord->getSource(),
            $rawRecord->getConnectionRef(),
            $mapped->externalId,
        );

        if (null === $order) {
            $order = new IngestOrder(
                companyId: $companyId,
                connectionRef: $rawRecord->getConnectionRef(),
                shopRef: $rawRecord->getShopRef(),
                source: $rawRecord->getSource(),
                scheme: $mapped->scheme,
                externalId: $mapped->externalId,
                orderedAt: $mapped->orderedAt,
                rawStatus: $mapped->rawStatus,
                status: $status,
                statusObservedAt: $observedAt,
                externalOrderId: $mapped->externalOrderId,
                rawSubstatus: $mapped->rawSubstatus,
                lastRawRecordId: $rawRecord->getId(),
                attributes: [] === $mapped->attributes ? null : $mapped->attributes,
            );
            $this->orderRepository->save($order);
            $this->entityManager->flush();

            $this->appendStatusEvent($order, $mapped->rawStatus, $status, null, $observedAt, $rawRecord->getId());
            $this->applyItems($order, $mapped->items, $rawRecord);

            return;
        }

        $previousStatus = $order->getStatus();
        $statusChanged = $previousStatus !== $status || $order->getRawStatus() !== $mapped->rawStatus;

        // Наблюдение фиксируется, если статус ОТЛИЧАЕТСЯ от текущего — иначе
        // часовой опрос дал бы 24 одинаковые строки в сутки на заказ. При этом
        // устаревшее наблюдение другого статуса всё равно записывается: это
        // факт, который был.
        if ($statusChanged) {
            $this->appendStatusEvent($order, $mapped->rawStatus, $status, $previousStatus, $observedAt, $rawRecord->getId());
        }

        $order->observeStatus($mapped->rawStatus, $status, $observedAt, $mapped->rawSubstatus, $rawRecord->getId());
        $order->mergeAttributes($mapped->attributes);
        if (null !== $mapped->externalOrderId) {
            $order->setExternalOrderId($mapped->externalOrderId);
        }

        $this->applyItems($order, $mapped->items, $rawRecord);
    }

    private function appendStatusEvent(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        ?IngestOrderStatus $previousStatus,
        \DateTimeImmutable $observedAt,
        string $rawRecordId,
    ): void {
        // Одно наблюдение — одно событие. Устаревшее наблюдение не двигает
        // статус заказа, поэтому «статус отличается» остаётся истиной навсегда,
        // и без этой проверки каждый повторный прогон того же сырья дописывал
        // бы ещё одну копию.
        if ($this->statusEventRepository->existsObservation($order->getCompanyId(), $order->getId(), $rawRecordId, $rawStatus)) {
            return;
        }

        $this->statusEventRepository->save(new IngestOrderStatusEvent(
            companyId: $order->getCompanyId(),
            orderId: $order->getId(),
            rawStatus: $rawStatus,
            status: $status,
            observedAt: $observedAt,
            previousStatus: $previousStatus,
            rawRecordId: $rawRecordId,
        ));
    }

    /**
     * @param list<MappedOrderItem> $mappedItems
     */
    private function applyItems(IngestOrder $order, array $mappedItems, IngestRawRecord $rawRecord): void
    {
        if ([] === $mappedItems) {
            return;
        }

        $existing = $this->itemRepository->findByOrderIndexedByLineKey($order->getCompanyId(), $order->getId());

        $sourceDataRows = [];
        foreach ($mappedItems as $index => $mappedItem) {
            $sourceDataRows[$index] = $mappedItem->sourceData;
        }

        $resolutions = $this->listingResolverRegistry->resolveMany(
            $rawRecord->getSource(),
            $order->getCompanyId(),
            $sourceDataRows,
        );

        foreach ($mappedItems as $index => $mappedItem) {
            $item = $existing[$mappedItem->lineKey] ?? null;

            if (null === $item) {
                $item = new IngestOrderItem(
                    companyId: $order->getCompanyId(),
                    orderId: $order->getId(),
                    lineNo: $mappedItem->lineNo,
                    lineKey: $mappedItem->lineKey,
                    quantity: $mappedItem->quantity,
                    externalSku: $mappedItem->externalSku,
                    offerId: $mappedItem->offerId,
                    barcode: $mappedItem->barcode,
                    name: $mappedItem->name,
                    priceMinor: $mappedItem->priceMinor,
                    currency: $mappedItem->currency,
                    marketplaceBuyout: $mappedItem->marketplaceBuyout,
                );
                $this->itemRepository->save($item);
            } else {
                $item->refresh(
                    $mappedItem->lineNo,
                    $mappedItem->quantity,
                    $mappedItem->name,
                    $mappedItem->priceMinor,
                    $mappedItem->currency,
                    $mappedItem->marketplaceBuyout,
                );
            }

            $resolution = $resolutions[$index] ?? null;

            // Нерезолвленное не теряется: ключ, по которому пытались искать,
            // сохраняется даже когда листинг не найден — это очередь на разбор,
            // а не молчаливый NULL.
            $listingSku = $resolution?->listingSku;
            if (null === $listingSku) {
                $listingSku = $mappedItem->externalSku;
            }

            $item->linkListing($resolution?->listingId, $listingSku);
        }
    }
}
