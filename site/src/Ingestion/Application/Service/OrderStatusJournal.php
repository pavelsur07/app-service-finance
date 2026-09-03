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
 * - события различаются ПОРЯДКОВЫМ НОМЕРОМ внутри пары (сырьё, заказ), а не
 *   содержанием. Содержательный ключ подавлял бы законный повтор: одно сырьё
 *   может нести A → B → A → B, и второе «A → B» совпало бы с первым — заказ
 *   переход применил бы, а журнал потерял;
 * - у каждого события есть ещё и номер В ИСТОРИИ ЗАКАЗА (`recordedSeq`),
 *   выдаваемый счётчиком самого заказа под его блокировкой. Он задаёт порядок
 *   применения, когда время наблюдения у двух событий совпало: `occurrence`
 *   для этого не годится, он считает наблюдения одного сырья и обнуляется на
 *   каждой новой сырьевой записи.
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
     * @param array<string, int> $occurrences последний номер на заказ, изменяется по ссылке
     */
    public function recordOpening(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        string $rawRecordId,
        array &$occurrences,
    ): void {
        $this->append($order, $rawStatus, $status, null, $observedAt, $rawRecordId, $occurrences, true);
    }

    /**
     * Наблюдение статуса существующего заказа.
     *
     * @param array<string, int> $occurrences последний номер на заказ, изменяется по ссылке
     */
    public function observe(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus,
        string $rawRecordId,
        array &$occurrences,
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
            $this->append($order, $rawStatus, $status, $previousStatus, $observedAt, $rawRecordId, $occurrences, $accepted);
        }

        // «Принято» и «изменилось» — разные ответы. Принятым считается любое
        // наблюдение не старше текущей отметки, включая повторяющее тот же
        // статус; изменением — только то, которое статус действительно
        // сдвинуло.
        return new StatusObservationOutcome(
            accepted: $accepted,
            changed: $accepted && $differs,
            // Записано в журнал — то же условие, при котором пишется событие.
            recorded: $differs,
        );
    }

    /**
     * Повторный разбор УЖЕ обработанного сырья: состояние заказа
     * пересчитывается, событий не появляется.
     *
     * Повтор — не наблюдение. Наблюдение произошло один раз, тогда же оно и
     * попало в журнал (или осознанно не попало, потому что ничего не меняло).
     * Если бы повтор шёл общим путём, он дописывал бы копии событий при каждой
     * повторной доставке сообщения.
     */
    public function reapply(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus,
        string $rawRecordId,
    ): StatusObservationOutcome {
        // Равное время — не повод перезаписывать ЧУЖОЕ наблюдение.
        //
        // observeStatus() отклоняет только строго более старое, поэтому при
        // одинаковом `fetchedAt` двух разных сырьевых записей повтор первой
        // затирал бы состояние, записанное второй, и не оставлял бы даже
        // строки в журнале. Для наблюдения «последний победил» — осознанное
        // правило, для ПОВТОРА уже разобранного сырья — нет: повтор ничего
        // нового не сообщает.
        $current = $order->getStatusObservedAt();
        if (null !== $current && $observedAt <= $current && $order->getLastRawRecordId() !== $rawRecordId) {
            return StatusObservationOutcome::rejected();
        }

        $differs = $order->getStatus() !== $status || $order->getRawStatus() !== $rawStatus;
        $accepted = $order->observeStatus($rawStatus, $status, $observedAt, $rawSubstatus, $rawRecordId);

        return new StatusObservationOutcome(accepted: $accepted, changed: $accepted && $differs);
    }

    /**
     * @param array<string, int> $occurrences последний номер на заказ, изменяется по ссылке
     */
    private function append(
        IngestOrder $order,
        string $rawStatus,
        IngestOrderStatus $status,
        ?IngestOrderStatus $previousStatus,
        \DateTimeImmutable $observedAt,
        string $rawRecordId,
        array &$occurrences,
        bool $applied,
    ): void {
        $occurrence = ($occurrences[$order->getId()] ?? -1) + 1;
        $occurrences[$order->getId()] = $occurrence;

        $this->eventRepository->save(new IngestOrderStatusEvent(
            companyId: $order->getCompanyId(),
            orderId: $order->getId(),
            rawStatus: $rawStatus,
            status: $status,
            observedAt: $observedAt,
            previousStatus: $previousStatus,
            rawRecordId: $rawRecordId,
            applied: $applied,
            occurrence: $occurrence,
            // Номер выдаёт САМ ЗАКАЗ и под своей блокировкой: он и задаёт
            // порядок применения, когда время наблюдения у двух событий
            // совпало. Внутрипроцессный счётчик `occurrence` для этого не
            // годится — он считает наблюдения одного сырья и обнуляется на
            // каждой новой сырьевой записи.
            recordedSeq: $order->nextStatusEventSequence(),
        ));
    }
}
