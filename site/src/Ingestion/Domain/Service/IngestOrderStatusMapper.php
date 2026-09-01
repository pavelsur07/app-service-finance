<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;

/**
 * Единственное место, где сырые статусы маркетплейсов превращаются в сквозной
 * enum.
 *
 * Почему одно место: одно доменное понятие («доставлено», «отменено») обязано
 * определяться один раз — иначе монитор, отчёт и апсерт со временем разойдутся
 * в показаниях (CLAUDE.md, «Health-гейты»).
 *
 * Чего здесь НЕТ намеренно:
 * - `substatus` Ozon. В выгрузке `posting_on_way_to_city` (68) и
 *   `posting_in_pickup_point` (19) оба лежат под одним `delivering`. Это
 *   уточнение доставки, а не статус: кардинальность выше, стабильность ниже.
 *   Хранится дословно рядом, в нормализацию не участвует.
 * - Терминальность. Она на {@see IngestOrderStatus::isTerminal()}, потому что
 *   её спрашивают не только отсюда.
 */
final class IngestOrderStatusMapper
{
    /**
     * У WB нет единого поля статуса: `/api/v3/orders/status` отдаёт ДВЕ
     * независимые оси. В выгрузке наблюдалась пара `new / canceled_by_client`,
     * где supplierStatus говорит «новый», а wbStatus — «отменён клиентом»;
     * одной осью это не выражается.
     *
     * Поэтому «сырая строка» для WB — детерминированная склейка того, что
     * реально пришло, без интерпретации. Притвориться, что WB отдал одно поле,
     * было бы враньём худшим, чем склейка.
     */
    public static function encodeWbStatus(string $supplierStatus, string $wbStatus): string
    {
        return sprintf('supplierStatus=%s;wbStatus=%s', $supplierStatus, $wbStatus);
    }

    /**
     * Поток statistics статуса не отдаёт вовсе — только признак отмены.
     */
    public static function encodeWbCancelFlag(bool $isCancel): string
    {
        return sprintf('isCancel=%s', $isCancel ? 'true' : 'false');
    }

    /**
     * @return array{supplierStatus: string, wbStatus: string}|null
     */
    public static function decodeWbStatus(string $rawStatus): ?array
    {
        if (1 !== preg_match('/^supplierStatus=([^;]*);wbStatus=(.*)$/', $rawStatus, $m)) {
            return null;
        }

        return ['supplierStatus' => $m[1], 'wbStatus' => $m[2]];
    }

    public function map(IngestSource $source, IngestOrderScheme $scheme, string $rawStatus): IngestOrderStatus
    {
        $raw = trim($rawStatus);
        if ('' === $raw) {
            return IngestOrderStatus::UNKNOWN;
        }

        return match ($source) {
            IngestSource::OZON => $this->mapOzon($raw),
            IngestSource::WILDBERRIES => $this->mapWildberries($raw),
            IngestSource::OZON_PERFORMANCE => IngestOrderStatus::UNKNOWN,
        };
    }

    /**
     * Наблюдённые в выгрузке значения FBO: awaiting_deliver, delivering,
     * delivered, cancelled.
     *
     * Остальные токены засеяны из документации Ozon и на реальных данных НЕ
     * проверены — в окне выгрузки было ноль FBS-отправлений. Оставить словарь
     * FBS пустым «чтобы всё честно падало в UNKNOWN» значило бы устроить шторм
     * issue на первой же реальной FBS-загрузке, который никто не разберёт.
     * Непроверенность зафиксирована именем теста
     * `testFbsDictionaryIsDocumentationDerivedAndUnverified`.
     */
    private function mapOzon(string $raw): IngestOrderStatus
    {
        return match ($raw) {
            'acceptance_in_progress', 'awaiting_registration', 'awaiting_approve',
            'awaiting_packaging', 'awaiting_deliver', 'not_accepted' => IngestOrderStatus::ORDERED,
            'delivering', 'driver_pickup', 'arbitration', 'client_arbitration' => IngestOrderStatus::SHIPPED,
            'delivered' => IngestOrderStatus::DELIVERED,
            'cancelled', 'cancelled_from_split_pending' => IngestOrderStatus::CANCELLED,
            'returned', 'returning' => IngestOrderStatus::RETURNED,
            default => IngestOrderStatus::UNKNOWN,
        };
    }

    /**
     * Ключ маппинга — пара осей, а не одна ось. Наблюдённые комбинации
     * разбираются точно, остальное — документированным правилом-фоллбэком:
     * это правило, а не догадка про конкретный токен, и оно тестируемо.
     */
    private function mapWildberries(string $raw): IngestOrderStatus
    {
        if (str_starts_with($raw, 'isCancel=')) {
            return 'isCancel=true' === $raw ? IngestOrderStatus::CANCELLED : IngestOrderStatus::ORDERED;
        }

        $pair = self::decodeWbStatus($raw);
        if (null === $pair) {
            return IngestOrderStatus::UNKNOWN;
        }

        // wbStatus — про движение товара, поэтому он старше supplierStatus.
        return match (true) {
            in_array($pair['wbStatus'], ['canceled', 'canceled_by_client', 'declined_by_client'], true) => IngestOrderStatus::CANCELLED,
            'defect' === $pair['wbStatus'] => IngestOrderStatus::RETURNED,
            'sold' === $pair['wbStatus'] => IngestOrderStatus::DELIVERED,
            in_array($pair['wbStatus'], ['sorted', 'ready_for_pickup', 'received'], true) => IngestOrderStatus::SHIPPED,
            'waiting' === $pair['wbStatus'] => IngestOrderStatus::ORDERED,
            'new' === $pair['supplierStatus'] => IngestOrderStatus::ORDERED,
            in_array($pair['supplierStatus'], ['confirm', 'complete'], true) => IngestOrderStatus::SHIPPED,
            default => IngestOrderStatus::UNKNOWN,
        };
    }
}
