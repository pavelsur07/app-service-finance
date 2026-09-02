<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderBatch;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\Domain\Contract\OrderMapperInterface;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestSource;

/**
 * Маппер заказов Wildberries для обоих потоков.
 *
 * Один класс на два ресурса: сшивка по `rid = srid` работает только если обе
 * формы приводятся к одному `externalId` одним и тем же правилом. Разнести это
 * по двум классам значило бы завести два места, где определяется идентичность
 * заказа, — и они бы разошлись.
 *
 * Заказ WB — это ОДНА товарная позиция: и `/api/v3/orders`, и
 * `/api/v1/supplier/orders` отдают строку на единицу товара, а не на корзину.
 * Поэтому у каждого заказа ровно одна позиция.
 */
final readonly class WbOrderMapper implements OrderMapperInterface
{
    /**
     * Числовые коды валют ISO 4217: WB отдаёт `currencyCode` числом (в
     * выгрузке 643). Список намеренно короткий — только то, что маркетплейс
     * реально может прислать; неизвестный код делает строку недействительной,
     * а не превращает деньги в валюту по умолчанию.
     */
    private const CURRENCY_BY_NUMERIC_CODE = [
        643 => 'RUB',
        840 => 'USD',
        978 => 'EUR',
        933 => 'BYN',
        398 => 'KZT',
        51 => 'AMD',
        417 => 'KGS',
        860 => 'UZS',
    ];

    /**
     * Тип склада из statistics. Оба значения наблюдались в реальной выгрузке:
     * «Склад продавца» (поставка продавцом) и «Склад WB» (поставка
     * маркетплейсом).
     *
     * Словарь строгий. Незнакомое или отсутствующее значение даёт
     * {@see IngestOrderScheme::UNKNOWN}, а не одну из настоящих схем: опечатка
     * или новый тип склада молча меняли бы схему исполнения заказа и всю
     * отчётность по ней.
     */
    private const SCHEME_BY_WAREHOUSE_TYPE = [
        'Склад продавца' => IngestOrderScheme::FBS,
        'Склад WB' => IngestOrderScheme::FBO,
    ];

    /**
     * Границы BIGINT, в котором хранится priceMinor.
     *
     * Значение вне диапазона просто не запишется, и узнать об этом на вставке —
     * худший момент: raw к тому времени уже помечен обработанным. Сравнение
     * идёт по строке, потому что приведение к int здесь и есть источник
     * искажения, от которого мы защищаемся.
     */
    private const BIGINT_MAX_DIGITS = '9223372036854775807';
    private const BIGINT_MIN_DIGITS = '9223372036854775808';

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    public function resourceTypes(): array
    {
        return [WbResourceType::ORDERS_MARKETPLACE, WbResourceType::ORDERS_STATISTICS];
    }

    public function map(IngestRawRecord $rawRecord, iterable $rows): MappedOrderBatch
    {
        $isStatistics = WbResourceType::ORDERS_STATISTICS === $rawRecord->getResourceType();

        $orders = [];
        $skipped = [];

        foreach ($rows as $row) {
            // Служебный маркер пустого окна — единственный ожидаемый повод
            // ничего не разбирать.
            if (true === ($row['_ingestion_empty'] ?? null)) {
                continue;
            }

            $mapped = $isStatistics ? $this->mapStatisticsRow($row) : $this->mapMarketplaceRow($row);
            if (null !== $mapped['error'] || null === $mapped['order']) {
                $skipped[] = ['reason' => $mapped['error'] ?? 'unmapped_row', 'hint' => $mapped['hint']];
                continue;
            }

            $orders[] = $mapped['order'];
        }

        return new MappedOrderBatch($orders, $skipped);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{order: ?MappedOrder, error: ?string, hint: ?string}
     */
    private function mapMarketplaceRow(array $row): array
    {
        // rid — ключ сшивки с statistics (`srid`). Без него заказ невозможно
        // связать со вторым потоком, и он бессмыслен.
        $rid = $this->stringOrNull($row['rid'] ?? null);
        if (null === $rid) {
            return $this->skip('missing_rid', null);
        }

        $orderedAt = WbOrderDateParser::parseMarketplaceInstant($row['createdAt'] ?? null);
        if (null === $orderedAt) {
            return $this->skip('unparsable_created_at', $rid);
        }

        $item = $this->marketplaceItem($row);
        if (is_string($item)) {
            return $this->skip($item, $rid);
        }

        // Статуса может не быть: `/api/v3/orders/status` отдаёт не всё, что
        // отдал `/api/v3/orders`. Заказ при этом существует, поэтому он не
        // теряется — обе оси уходят пустыми, статус деградирует в UNKNOWN и
        // попадает в видимую очередь на разбор.
        $status = $row['_ingestion_status'] ?? null;
        $hasStatus = is_array($status);
        $supplierStatus = $hasStatus ? (string) ($status['supplierStatus'] ?? '') : '';
        $wbStatus = $hasStatus ? (string) ($status['wbStatus'] ?? '') : '';

        return [
            'order' => new MappedOrder(
                externalId: $rid,
                // marketplace-api обслуживает поставки со склада продавца,
                // поэтому схема здесь известна из самого эндпоинта, а не из
                // содержимого ответа.
                scheme: IngestOrderScheme::FBS,
                orderedAt: $orderedAt,
                rawStatus: IngestOrderStatusMapper::encodeWbStatus($supplierStatus, $wbStatus),
                items: [$item],
                externalOrderId: $this->stringOrNull($row['id'] ?? null),
                rawSubstatus: null,
                attributes: $this->marketplaceAttributes($row),
                statusAttributes: $this->marketplaceStatusAttributes($status),
                // Ответ без строки статуса ничего о статусе не говорит: он не
                // должен превращать известный этап жизни заказа в UNKNOWN.
                statusObserved: $hasStatus,
            ),
            'error' => null,
            'hint' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{order: ?MappedOrder, error: ?string, hint: ?string}
     */
    private function mapStatisticsRow(array $row): array
    {
        $srid = $this->stringOrNull($row['srid'] ?? null);
        if (null === $srid) {
            return $this->skip('missing_srid', null);
        }

        $orderedAt = WbOrderDateParser::parseStatisticsInstant($row['date'] ?? null);
        if (null === $orderedAt) {
            return $this->skip('unparsable_date', $srid);
        }

        $isCancel = $row['isCancel'] ?? null;
        if (!is_bool($isCancel)) {
            // Приведение произвольного значения к bool перевернуло бы признак
            // отмены: строка "false" стала бы true.
            return $this->skip('malformed_is_cancel', $srid);
        }

        $item = $this->statisticsItem($row);
        if (is_string($item)) {
            return $this->skip($item, $srid);
        }

        return [
            'order' => new MappedOrder(
                externalId: $srid,
                scheme: $this->schemeFromWarehouseType($row['warehouseType'] ?? null),
                orderedAt: $orderedAt,
                // Поток statistics статуса не отдаёт вовсе — только признак
                // отмены. Притвориться, что это статус, было бы враньём.
                rawStatus: IngestOrderStatusMapper::encodeWbCancelFlag($isCancel),
                items: [$item],
                // externalOrderId принадлежит marketplace-потоку: там это
                // номер заказа WB. gNumber — номер группы, другая сущность, и
                // он хранится отдельно в атрибутах. Подставлять его сюда
                // значило бы менять смысл поля в зависимости от того, какой
                // поток пришёл последним.
                externalOrderId: null,
                rawSubstatus: null,
                // У потока изменений снимочных атрибутов нет: он описывает
                // не заказ, а его изменение.
                // Описательное — по оси частичного наблюдения, отмена — по
                // статусной: у них разная свежесть и разные владельцы.
                attributes: $this->statisticsAttributes($row),
                statusAttributes: $this->statisticsStatusAttributes($row),
                // Поток изменений не отдаёт состава заказа: он вправе
                // добавить недостающую позицию, но не переписывать и не
                // удалять то, что видел marketplace.
                itemsAuthoritative: false,
                // Статусом является только ОТМЕНА. `isCancel = false` говорит
                // «отмены не было» и о движении товара не сообщает ничего;
                // принять это за наблюдение значило бы затирать SHIPPED или
                // DELIVERED, тем более что крон ставит statistics после
                // marketplace — затирало бы почти всегда.
                statusObserved: $isCancel,
            ),
            'error' => null,
            'hint' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return MappedOrderItem|string позиция либо причина отбраковки
     */
    private function marketplaceItem(array $row): MappedOrderItem|string
    {
        $nmId = $this->stringOrNull($row['nmId'] ?? null);
        $article = $this->stringOrNull($row['article'] ?? null);
        if (null === $nmId && null === $article) {
            return 'missing_product_identity';
        }

        // `price` у marketplace-api уже в КОПЕЙКАХ (в выгрузке 195700 при цене
        // 1957 ₽). Прогнать его через рублёвую конвертацию значило бы завысить
        // цену в сто раз.
        $priceMinor = $this->minorFromMinor($row['price'] ?? null);
        if (null === $priceMinor && null !== ($row['price'] ?? null)) {
            return 'malformed_price';
        }

        $currency = $this->currencyFromNumericCode($row['currencyCode'] ?? null);
        if (false === $currency) {
            return 'unknown_currency_code';
        }

        $barcode = $this->firstSku($row['skus'] ?? null);

        return new MappedOrderItem(
            lineNo: 0,
            lineKey: $this->lineKey($nmId, $article),
            quantity: 1,
            externalSku: $nmId,
            offerId: $article,
            barcode: $barcode,
            name: null,
            priceMinor: $priceMinor,
            currency: $currency,
            marketplaceBuyout: false,
            sourceData: array_filter([
                'nm_id' => $nmId,
                'supplier_article' => $article,
                'barcode' => $barcode,
                'chrt_id' => $this->stringOrNull($row['chrtId'] ?? null),
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return MappedOrderItem|string позиция либо причина отбраковки
     */
    private function statisticsItem(array $row): MappedOrderItem|string
    {
        $nmId = $this->stringOrNull($row['nmId'] ?? null);
        $article = $this->stringOrNull($row['supplierArticle'] ?? null);
        if (null === $nmId && null === $article) {
            return 'missing_product_identity';
        }

        // Цену позиции statistics НЕ задаёт.
        //
        // `finishedPrice` — цена после всех скидок в рублях, а `price`
        // marketplace-api — цена продажи в копейках. Это разные величины, и
        // класть их в одну колонку значило бы менять её смысл в зависимости от
        // того, какой поток заполнил запись. Сумма не теряется: она уходит в
        // атрибуты заказа под собственным именем.
        if (null !== ($row['finishedPrice'] ?? null) && null === $this->minorFromMajor($row['finishedPrice'])) {
            return 'malformed_price';
        }

        return new MappedOrderItem(
            lineNo: 0,
            lineKey: $this->lineKey($nmId, $article),
            quantity: 1,
            externalSku: $nmId,
            offerId: $article,
            barcode: $this->stringOrNull($row['barcode'] ?? null),
            name: $this->stringOrNull($row['subject'] ?? null),
            priceMinor: null,
            // Валюта НЕ подставляется: поля валюты у statistics-api нет
            // вовсе, а «наверное, рубли» — финансовое утверждение без
            // основания. Для заказа, который есть и в marketplace, валюту даёт
            // тот поток; для остальных она честно остаётся неизвестной.
            currency: null,
            marketplaceBuyout: false,
            sourceData: array_filter([
                'nm_id' => $nmId,
                'supplier_article' => $article,
                'barcode' => $this->stringOrNull($row['barcode'] ?? null),
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    private function schemeFromWarehouseType(mixed $warehouseType): IngestOrderScheme
    {
        if (!is_string($warehouseType)) {
            return IngestOrderScheme::UNKNOWN;
        }

        return self::SCHEME_BY_WAREHOUSE_TYPE[trim($warehouseType)] ?? IngestOrderScheme::UNKNOWN;
    }

    /**
     * Ключ позиции строится из пары идентификаторов — так же, как у Ozon:
     * заказ WB несёт одну позицию, но правило идентичности обязано быть одним
     * на весь модуль.
     */
    private function lineKey(?string $nmId, ?string $article): string
    {
        $base = 'sku:'.($nmId ?? '').'|offer:'.($article ?? '');

        return (mb_strlen($base) > 80 ? 'h:'.hash('sha256', $base) : $base).'#0';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function marketplaceAttributes(array $row): array
    {
        return array_filter([
            'wb_order_id' => $this->stringOrNull($row['id'] ?? null),
            'order_uid' => $this->stringOrNull($row['orderUid'] ?? null),
            'supply_id' => $this->stringOrNull($row['supplyId'] ?? null),
            'warehouse_id' => $this->stringOrNull($row['warehouseId'] ?? null),
            'delivery_type' => $this->stringOrNull($row['deliveryType'] ?? null),
            'chrt_id' => $this->stringOrNull($row['chrtId'] ?? null),
        ], static fn (mixed $v): bool => null !== $v);
    }

    /**
     * Обе оси сохраняются дословно рядом с нормализованным статусом:
     * supplierStatus в нормализации не участвует, но объясняет её. Это
     * статусная ось, и она меняется во времени.
     *
     * @return array<string, mixed>
     */
    private function marketplaceStatusAttributes(mixed $status): array
    {
        if (!is_array($status)) {
            return [];
        }

        $attributes = [
            'supplier_status' => $this->stringOrNull($status['supplierStatus'] ?? null),
            'wb_status' => $this->stringOrNull($status['wbStatus'] ?? null),
        ];

        if (is_bool($status['isCancellable'] ?? null)) {
            $attributes['is_cancellable'] = $status['isCancellable'];
        }

        return array_filter($attributes, static fn (mixed $v): bool => null !== $v);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function statisticsAttributes(array $row): array
    {
        $attributes = array_filter([
            'g_number' => $this->stringOrNull($row['gNumber'] ?? null),
            'warehouse_name' => $this->stringOrNull($row['warehouseName'] ?? null),
            'warehouse_type' => $this->stringOrNull($row['warehouseType'] ?? null),
            'region_name' => $this->stringOrNull($row['regionName'] ?? null),
            'brand' => $this->stringOrNull($row['brand'] ?? null),
            'tech_size' => $this->stringOrNull($row['techSize'] ?? null),
        ], static fn (mixed $v): bool => null !== $v);

        // Сумма из statistics живёт под собственным именем: её семантика
        // (цена после всех скидок) отличается от цены позиции marketplace.
        $finishedPriceMinor = $this->minorFromMajor($row['finishedPrice'] ?? null);
        if (null !== $finishedPriceMinor) {
            $attributes['finished_price_minor'] = $finishedPriceMinor;
        }

        return $attributes;
    }

    /**
     * Статусная ось потока изменений — только отмена. Всё остальное он
     * сообщает независимо от того, менялся ли статус, поэтому живёт на оси
     * частичного наблюдения.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function statisticsStatusAttributes(array $row): array
    {
        $attributes = [];

        if (is_bool($row['isCancel'] ?? null)) {
            $attributes['is_cancel'] = $row['isCancel'];
        }

        $cancelledAt = WbOrderDateParser::parseStatisticsInstant($row['cancelDate'] ?? null);
        if (null !== $cancelledAt) {
            $attributes['cancelled_at'] = $cancelledAt->format(\DATE_ATOM);
        }

        return $attributes;
    }

    /**
     * Значение УЖЕ в минорных единицах: принимаем только целое.
     */
    private function minorFromMinor(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && 1 === preg_match('/^(-?)0*(\d+)$/', trim($value), $m)) {
            // Канонизация БЕЗ приведения к int: строка длиннее PHP_INT_MAX
            // молча превратилась бы в предельное целое, то есть сохранила бы
            // другую денежную величину без единого признака ошибки.
            // `0*(\d+)` гарантирует непустую группу: ведущие нули съедает
            // первая часть, «000» даёт «0».
            $digits = $m[2];
            if (!$this->fitsBigint($digits, '-' === $m[1])) {
                return null;
            }

            return '0' === $digits ? '0' : $m[1].$digits;
        }

        return null;
    }

    /**
     * Значение в мажорных единицах: переводим в минорные через строку, а не
     * умножением float на 100 — двоичная дробь на деньгах даёт 189999 вместо
     * 190000.
     */
    private function minorFromMajor(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }

        $raw = trim((string) $value);
        // Не более двух знаков после разделителя: у WB цены копеечные, и
        // третий знак означает смену контракта. Усечение молча превращало бы
        // 10,999 ₽ в 10,99 ₽ — тихое искажение денег вместо видимой ошибки.
        if (1 !== preg_match('/^(-?)(\d+)(?:[.,](\d{1,2}))?$/', $raw, $m)) {
            return null;
        }

        $fraction = str_pad($m[3] ?? '', 2, '0');
        $digits = ltrim($m[2].$fraction, '0');

        if (!$this->fitsBigint($digits, '-' === $m[1])) {
            return null;
        }

        // Ноль канонизируем без знака: «-0» — то же число, но другая строка.
        return '' === $digits ? '0' : $m[1].$digits;
    }

    /**
     * @return string|false|null строка валюты, null если кода нет, false если код неизвестен
     */
    private function currencyFromNumericCode(mixed $value): string|false|null
    {
        if (null === $value) {
            return null;
        }

        if (!is_int($value)) {
            return false;
        }

        return self::CURRENCY_BY_NUMERIC_CODE[$value] ?? false;
    }

    private function firstSku(mixed $value): ?string
    {
        if (!is_array($value) || !array_is_list($value) || [] === $value) {
            return null;
        }

        return $this->stringOrNull($value[0]);
    }

    /**
     * @return array{order: ?MappedOrder, error: ?string, hint: ?string}
     */
    private function skip(string $reason, ?string $hint): array
    {
        return ['order' => null, 'error' => $reason, 'hint' => $hint];
    }

    /**
     * Помещается ли число в BIGINT. Сравнение по строке: длина, затем
     * лексикографически, потому что модуль отрицательной границы на единицу
     * больше положительной.
     */
    private function fitsBigint(string $digits, bool $isNegative): bool
    {
        $limit = $isNegative ? self::BIGINT_MIN_DIGITS : self::BIGINT_MAX_DIGITS;
        $length = mb_strlen($digits);
        $limitLength = mb_strlen($limit);

        if ($length !== $limitLength) {
            return $length < $limitLength;
        }

        // Именно strcmp: `$digits <= $limit` для двух numeric-string PHP
        // выполняет ЧИСЛОВОЕ сравнение и у границы BIGINT приводит операнды к
        // float, где соседние значения перестают различаться. Тогда
        // 9223372036854775808 прошло бы как допустимое, и вместо видимой
        // отбраковки PostgreSQL получил бы выход за диапазон — а это ошибка
        // записи, на которой нормализация всего батча зацикливается.
        return strcmp($digits, $limit) <= 0;
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
