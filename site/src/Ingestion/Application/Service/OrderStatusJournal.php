<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\StatusObservationOutcome;
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
 *   который был, — но текущее состояние заказа назад не двигает и переходом
 *   не считается: у такой записи `applied = false` и пустой `previousStatus`;
 * - одно наблюдение — одно событие: ключ дедупликации собирается из сырья и
 *   сырого статуса, потому что Doctrine-запрос не видит непрофлашенные
 *   сущности, и без локального ключа повтор в одном батче дал бы дубль.
 *
 * Повторный прогон уже разобранного сырья наблюдением НЕ является и идёт
 * через {@see reapply()}: см. комментарий там.
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
        $this->append($order, $rawStatus, $status, null, $observedAt, $rawRecordId, $seenObservations, true);
    }

    /**
     * Наблюдение статуса существующего заказа.
     *
     * @param array<string, true> $seenObservations изменяется по ссылке
     */
    public function observe(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus,
        string $rawRecordId,
        array &$seenObservations,
    ): StatusObservationOutcome {
        $previousStatus = $order->getStatus();
        $differs = $previousStatus !== $status || $order->getRawStatus() !== $rawStatus;

        // Свежесть выясняется ДО записи события: устаревшее наблюдение — тоже
        // факт и фиксируется, но переходом не является. Записанное как переход
        // (`previousStatus = DELIVERED`, `status = SHIPPED`), оно утверждало бы
        // движение заказа, которого не было; признак `applied` разделяет эти
        // два смысла, а previousStatus у неприменённого наблюдения пуст.
        $accepted = $order->observeStatus($rawStatus, $status, $observedAt, $rawSubstatus, $rawRecordId);

        if ($differs) {
            $this->append($order, $rawStatus, $status, $previousStatus, $observedAt, $rawRecordId, $seenObservations, $accepted);
        }

        // «Принято» и «изменилось» — разные ответы. Принятым считается любое
        // наблюдение не старше текущей отметки, включая повторяющее тот же
        // статус; изменением — только то, которое статус действительно
        // сдвинуло.
        return new StatusObservationOutcome(accepted: $accepted, changed: $accepted && $differs);
    }

    /**
     * Повторный разбор УЖЕ обработанного сырья: состояние заказа
     * пересчитывается, событий не появляется.
     *
     * Повтор — не наблюдение. Наблюдение произошло один раз, тогда же оно и
     * попало в журнал (или осознанно не попало, потому что ничего не меняло).
     * Если бы повтор шёл общим путём, он вёл бы себя по-разному в зависимости
     * от момента: сырьё, впервые разобранное при том же статусе, событий не
     * дало, а после следующей смены статуса тот же повтор внезапно дописал бы
     * событие с `previousStatus` из будущего — перевёрнутый переход,
     * появившийся из ничего.
     */
    public function reapply(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus,
        string $rawRecordId,
    ): StatusObservationOutcome {
        $differs = $order->getStatus() !== $status || $order->getRawStatus() !== $rawStatus;
        $accepted = $order->observeStatus($rawStatus, $status, $observedAt, $rawSubstatus, $rawRecordId);

        return new StatusObservationOutcome(accepted: $accepted, changed: $accepted && $differs);
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
        bool $applied,
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
            applied: $applied,
        ));
    }
}
