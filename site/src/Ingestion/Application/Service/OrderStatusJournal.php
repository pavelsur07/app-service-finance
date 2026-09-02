<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;

/**
 * Единственное место, где статус заказа наблюдается и попадает в журнал.
 *
 * Путей наблюдения два: разбор сырья из потоков маркетплейсов и почасовой
 * перепрос нетерминальных заказов. Инварианты у них общие, и разложить их по
 * двум местам значило бы завести две расходящиеся копии правил «когда писать
 * событие» и «когда двигать статус».
 *
 * Правила:
 * - событие пишется только при ОТЛИЧИИ от текущего статуса; иначе часовой
 *   опрос дал бы 24 одинаковые строки в сутки на каждый заказ;
 * - устаревшее наблюдение другого статуса всё равно фиксируется — это факт,
 *   который был, — но текущее состояние заказа назад не двигает;
 * - одно наблюдение — одно событие: ключ дедупликации собирается из сырья и
 *   сырого статуса, потому что Doctrine-запрос не видит непрофлашенные
 *   сущности, и без локального ключа повтор в одном батче дал бы дубль.
 */
final readonly class OrderStatusJournal
{
    public function __construct(private IngestOrderStatusEventRepository $eventRepository)
    {
    }

    /**
     * Открывающее событие только что созданного заказа.
     *
     * @param array<string, true> $seenObservations изменяется по ссылке
     */
    public function recordOpening(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        string $rawRecordId,
        array &$seenObservations,
    ): void {
        $this->append($order, $rawStatus, $status, null, $observedAt, $rawRecordId, $seenObservations);
    }

    /**
     * Наблюдение статуса существующего заказа.
     *
     * @param array<string, true> $seenObservations изменяется по ссылке
     *
     * @return bool сдвинулся ли статус заказа
     */
    public function observe(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus,
        string $rawRecordId,
        array &$seenObservations,
    ): bool {
        $previousStatus = $order->getStatus();

        if ($previousStatus !== $status || $order->getRawStatus() !== $rawStatus) {
            // Событие пишется ДО observeStatus(): иначе previousStatus уже
            // равнялся бы новому и запись теряла бы переход.
            $this->append($order, $rawStatus, $status, $previousStatus, $observedAt, $rawRecordId, $seenObservations);
        }

        return $order->observeStatus($rawStatus, $status, $observedAt, $rawSubstatus, $rawRecordId);
    }

    /**
     * @param array<string, true> $seenObservations изменяется по ссылке
     */
    private function append(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        ?IngestOrderStatus $previousStatus,
        \DateTimeImmutable $observedAt,
        string $rawRecordId,
        array &$seenObservations,
    ): void {
        $key = $order->getId()."\0".$rawStatus;
        if (isset($seenObservations[$key])) {
            return;
        }

        $seenObservations[$key] = true;

        $this->eventRepository->save(new IngestOrderStatusEvent(
            companyId: $order->getCompanyId(),
            orderId: $order->getId(),
            rawStatus: $rawStatus,
            status: $status,
            observedAt: $observedAt,
            previousStatus: $previousStatus,
            rawRecordId: $rawRecordId,
        ));
    }
}
