<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand;
use App\Ingestion\Application\DTO\RefreshOrderStatusesResult;
use App\Ingestion\Application\Service\OrderStatusJournal;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Почасовой перепрос статусов нетерминальных заказов.
 *
 * Зачем отдельный цикл. Потоки заказов фильтруются по времени СОЗДАНИЯ: Ozon
 * отдаёт отправления за окно создания, WB marketplace — тоже. Поэтому заказ
 * попадает в них один раз, и его дальнейшие смены статуса не увидит никто.
 * Единственное исключение — поток изменений WB statistics, но он сообщает
 * только об отмене.
 *
 * Почему без SyncJob и без курсора. Здесь нет позиции, которую можно
 * продвигать: множество опрашиваемых заказов задаётся их собственным
 * состоянием, а не окном времени. Заводить ради этого задачу синхронизации
 * значило бы упереться в ActiveBackfillExistsException на каждом втором часе.
 *
 * Порядок опроса — по возрастанию времени последнего наблюдения, поэтому при
 * исчерпании лимита первыми опрашиваются самые давно не проверенные заказы.
 */
final readonly class RefreshOrderStatusesAction
{
    /** Максимум номеров заказов в одном запросе статусов WB. */
    private const WB_STATUS_CHUNK = 1000;

    public function __construct(
        private MarketplaceSyncFacade $marketplaceSyncFacade,
        private IngestOrderRepository $orderRepository,
        private OzonOrdersClientInterface $ozonClient,
        private WbOrdersClientInterface $wbClient,
        private IngestOrderStatusMapper $statusMapper,
        private OrderStatusJournal $statusJournal,
        private RawStorageFacade $rawStorageFacade,
        private RecordNormalizationIssueAction $recordIssueAction,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshOrderStatusesCommand $command): RefreshOrderStatusesResult
    {
        // Зона приложения — то соглашение, в котором живёт схема: колонки
        // времени без зоны, и UTC-момент вернулся бы из базы сдвинутым.
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $orderedAfter = $now->modify(sprintf('-%d days', $command->days));

        $result = new RefreshOrderStatusesResult();

        /** @var array<string, true> $companies */
        $companies = [];

        // Подключения берутся через Facade: `Infrastructure/` чужого модуля
        // закрыт, и прямой запрос был бы нарушением границы модулей.
        foreach ($this->marketplaceSyncFacade->activeSellerConnections() as $connection) {
            $companyId = $connection->companyId;
            if (null !== $command->companyId && $companyId !== $command->companyId) {
                continue;
            }

            $source = IngestSource::tryFrom($connection->marketplace);
            if (null === $source) {
                continue;
            }

            $companies[$companyId] = true;

            $connectionRef = $connection->connectionRef;
            $orders = $this->orderRepository->findNonTerminalForRefresh(
                $companyId,
                $source,
                $connectionRef,
                $orderedAfter,
                $command->limitPerConnection,
            );

            if ([] !== $orders) {
                try {
                    $result = $this->refreshConnection($result, $source, $companyId, $connectionRef, $orders, $now);
                } catch (ConnectorAuthException|ConnectorRateLimitedException|ConnectorTransientException $exception) {
                    // Сбой одного подключения не должен останавливать остальные:
                    // ключ мог протухнуть у одного продавца, а лимит — сработать
                    // у другого. Оба случая ожидаемы и лечатся сами.
                    $result = $result->with(failedConnections: 1);
                    $this->logger->warning('Order status refresh skipped a connection.', [
                        'companyId' => $companyId,
                        'connectionRef' => $connectionRef,
                        'source' => $source->value,
                        'exceptionClass' => $exception::class,
                    ]);
                }
            }
        }

        // Зависшие заказы ищутся ПО КОМПАНИИ, а не по подключению, поэтому
        // проход вынесен из цикла: у компании с двумя кабинетами тот же запрос
        // выполнился бы дважды и — так как отметка ещё не сброшена в базу —
        // вернул бы те же заказы, дав второй экземпляр проблемы и удвоенный
        // счётчик.
        foreach (array_keys($companies) as $companyId) {
            $result = $this->stopStuckOrders($result, $companyId, $orderedAfter, $command->limitPerConnection, $now);
        }

        $this->entityManager->flush();

        $this->logger->info('Order status refresh finished.', [
            'companyId' => $command->companyId,
            'days' => $command->days,
            'polled' => $result->polled,
            'changed' => $result->changed,
            'missing' => $result->missing,
            'stopped' => $result->stopped,
            'failedConnections' => $result->failedConnections,
        ]);

        return $result;
    }

    /**
     * @param list<IngestOrder> $orders
     */
    private function refreshConnection(
        RefreshOrderStatusesResult $result,
        IngestSource $source,
        string $companyId,
        string $connectionRef,
        array $orders,
        \DateTimeImmutable $now,
    ): RefreshOrderStatusesResult {
        $observations = IngestSource::OZON === $source
            ? $this->pollOzon($companyId, $connectionRef, $orders)
            : $this->pollWildberries($companyId, $connectionRef, $orders);

        if ([] === $observations['rows']) {
            return $result->with(missing: $observations['missing']);
        }

        // Ответ маркетплейса сохраняется в raw ради аудита: без него нечем
        // объяснить, почему статус изменился именно так. Нормализация к этой
        // записи не применяется — маппера у ресурса нет, и запись сразу
        // помечается пропущенной, иначе она вечно висела бы в очереди.
        $rawRecordId = $this->storeAudit($source, $companyId, $connectionRef, $observations['rows'], $now);

        $seenObservations = [];
        $changed = 0;

        foreach ($observations['statuses'] as $orderId => $observation) {
            $order = $observations['orders'][$orderId];
            $status = $this->statusMapper->map($source, $order->getScheme(), $observation['rawStatus']);

            if ($this->statusJournal->observe(
                $order,
                $observation['rawStatus'],
                $status,
                $now,
                $observation['rawSubstatus'],
                $rawRecordId,
                $seenObservations,
            )) {
                ++$changed;
            }
        }

        return $result->with(
            polled: count($observations['statuses']),
            changed: $changed,
            missing: $observations['missing'],
        );
    }

    /**
     * @param list<IngestOrder> $orders
     *
     * @return array{rows: list<array<string, mixed>>, statuses: array<string, array{rawStatus: string, rawSubstatus: ?string}>, orders: array<string, IngestOrder>, missing: int}
     */
    private function pollOzon(string $companyId, string $connectionRef, array $orders): array
    {
        $rows = [];
        $statuses = [];
        $byId = [];
        $missing = 0;

        foreach ($orders as $order) {
            $posting = $this->ozonClient->fetchPosting(
                $companyId,
                $connectionRef,
                $order->getScheme(),
                $order->getExternalId(),
            );

            if (null === $posting) {
                ++$missing;
                continue;
            }

            $rawStatus = $this->stringOrNull($posting['status'] ?? null);
            if (null === $rawStatus) {
                // Отправление без статуса — испорченный ответ, но ронять из-за
                // него остальные заказы незачем: считаем непрочитанным.
                ++$missing;
                continue;
            }

            $rows[] = $posting;
            $statuses[$order->getId()] = [
                'rawStatus' => $rawStatus,
                'rawSubstatus' => $this->stringOrNull($posting['substatus'] ?? null),
            ];
            $byId[$order->getId()] = $order;
        }

        return ['rows' => $rows, 'statuses' => $statuses, 'orders' => $byId, 'missing' => $missing];
    }

    /**
     * @param list<IngestOrder> $orders
     *
     * @return array{rows: list<array<string, mixed>>, statuses: array<string, array{rawStatus: string, rawSubstatus: ?string}>, orders: array<string, IngestOrder>, missing: int}
     */
    private function pollWildberries(string $companyId, string $connectionRef, array $orders): array
    {
        // Опрашиваются только заказы, у которых есть номер marketplace-api.
        // Заказ, известный лишь из потока изменений statistics, спросить не у
        // кого: эндпоинта «статус по srid» у WB нет, и его отмену приносит
        // сам поток изменений.
        $byWbId = [];
        foreach ($orders as $order) {
            $wbOrderId = ($order->getAttributes() ?? [])['wb_order_id'] ?? null;
            if (is_string($wbOrderId) && 1 === preg_match('/^\d+$/', $wbOrderId)) {
                $byWbId[(int) $wbOrderId] = $order;
            }
        }

        if ([] === $byWbId) {
            return ['rows' => [], 'statuses' => [], 'orders' => [], 'missing' => 0];
        }

        $rows = [];
        $statuses = [];
        $byId = [];

        foreach (array_chunk(array_keys($byWbId), self::WB_STATUS_CHUNK) as $chunk) {
            foreach ($this->wbClient->fetchMarketplaceStatuses($companyId, $connectionRef, $chunk) as $wbOrderId => $row) {
                $order = $byWbId[$wbOrderId] ?? null;
                if (null === $order) {
                    continue;
                }

                $rows[] = $row;
                $statuses[$order->getId()] = [
                    'rawStatus' => IngestOrderStatusMapper::encodeWbStatus(
                        (string) $row['supplierStatus'],
                        (string) $row['wbStatus'],
                    ),
                    'rawSubstatus' => null,
                ];
                $byId[$order->getId()] = $order;
            }
        }

        return [
            'rows' => $rows,
            'statuses' => $statuses,
            'orders' => $byId,
            'missing' => count($byWbId) - count($statuses),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeAudit(
        IngestSource $source,
        string $companyId,
        string $connectionRef,
        array $rows,
        \DateTimeImmutable $now,
    ): string {
        $resourceType = IngestSource::OZON === $source
            ? OzonResourceType::ORDER_STATUS_REFRESH
            : WbResourceType::ORDER_STATUS_REFRESH;

        // Идентификатор прогона вместо syncJobId: задачи синхронизации здесь
        // нет, но поле обязательно, и подставлять чужой идентификатор нельзя.
        $runId = Uuid::uuid7()->toString();

        $records = $this->rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: $source,
            resourceType: $resourceType,
            externalId: sprintf('%s:run-%s', $resourceType, $runId),
            syncJobId: $runId,
            fetchedAt: $now,
            rows: $rows,
        ));

        foreach ($records as $record) {
            $record->markNormalizationSkipped();
        }

        return $records[0]->getId();
    }

    private function stopStuckOrders(
        RefreshOrderStatusesResult $result,
        string $companyId,
        \DateTimeImmutable $orderedBefore,
        int $limit,
        \DateTimeImmutable $now,
    ): RefreshOrderStatusesResult {
        $stuck = $this->orderRepository->findStuck($companyId, $orderedBefore, $limit);
        if ([] === $stuck) {
            return $result;
        }

        $stopped = 0;

        foreach ($stuck as $order) {
            // Опрашивать дальше бессмысленно, но и молча забывать нельзя:
            // заказ уходит в видимую очередь на разбор.
            $order->stopRefreshing($now);
            ++$stopped;

            // Проблема привязывается к сырью, из которого заказ наблюдался в
            // последний раз: у самой остановки своего payload нет, а
            // разбирающему нужно с чего-то начать. Заказ без сырья не
            // существует — он всегда создаётся нормализацией, — но если оно
            // почему-то потерялось, остановка не должна стать невидимой.
            $lastRawRecordId = $order->getLastRawRecordId();
            if (null === $lastRawRecordId) {
                $this->logger->warning('Stuck order has no raw record to attach the issue to.', [
                    'companyId' => $companyId,
                    'orderId' => $order->getId(),
                    'externalId' => $order->getExternalId(),
                ]);

                continue;
            }

            ($this->recordIssueAction)(new RecordNormalizationIssueCommand(
                companyId: $companyId,
                rawRecordId: $lastRawRecordId,
                operationGroupId: null,
                kind: NormalizationIssueKind::STUCK_ORDER,
                details: [
                    'source' => $order->getSource()->value,
                    'externalId' => $order->getExternalId(),
                    'status' => $order->getStatus()->value,
                    'orderedAt' => $order->getOrderedAt()->format(\DATE_ATOM),
                ],
            ));
        }

        return $result->with(stopped: $stopped);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}
