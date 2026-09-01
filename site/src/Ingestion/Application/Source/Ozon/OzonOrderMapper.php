<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderBatch;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\Domain\Contract\OrderMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestSource;

/**
 * Отправления Ozon (FBO и FBS) → нормализованные заказы.
 *
 * Естественный ключ — `posting_number`, а не `order_id`: в выгрузке 2026-09-01
 * 100 отправлений приходились на 89 заказов, и статусами живёт именно
 * отправление. `order_number` сохраняется рядом, чтобы связь с заказом не
 * терялась.
 */
final class OzonOrderMapper implements OrderMapperInterface
{
    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceTypes(): array
    {
        return [OzonResourceType::ORDERS_FBO, OzonResourceType::ORDERS_FBS];
    }

    /**
     * Ozon отдаёт RFC3339 с литеральным «Z» и микросекундами
     * (`2026-08-30T17:49:41.153418Z` — проверено на реальной выгрузке).
     * Вариант со смещением и вариант без дробной части тоже принимаем: они
     * валидны по стандарту, и полагаться на то, что источник никогда не
     * сменит форму записи, оснований нет. `u` разбирает 1–6 знаков.
     */
    private const DATE_FORMATS = [
        'Y-m-d\TH:i:s.u\Z',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:sP',
    ];

    public function map(IngestRawRecord $rawRecord, iterable $rows): MappedOrderBatch
    {
        // Схема берётся из типа ресурса, а не из payload'а: у FBO и FBS разные
        // эндпоинты и разные словари статусов, и определять её по содержимому
        // значило бы угадывать.
        $scheme = OzonResourceType::ORDERS_FBS === $rawRecord->getResourceType()
            ? IngestOrderScheme::FBS
            : IngestOrderScheme::FBO;

        $orders = [];
        $skipped = [];

        foreach ($rows as $row) {
            // Служебный маркер пустого окна — единственный ожидаемый повод
            // ничего не разбирать. Всё остальное, что не разобралось, — потеря,
            // и она обязана быть видимой.
            if (true === ($row['_ingestion_empty'] ?? null)) {
                continue;
            }

            $postingNumber = $this->stringOrNull($row['posting_number'] ?? null);
            if (null === $postingNumber) {
                $skipped[] = ['reason' => 'missing_posting_number', 'hint' => null];
                continue;
            }

            $status = $this->stringOrNull($row['status'] ?? null);
            if (null === $status) {
                $skipped[] = ['reason' => 'missing_status', 'hint' => $postingNumber];
                continue;
            }

            // Дату заказа НЕ подменяем временем загрузки: подстановка тихо
            // сдвигала бы заказ в сегодняшний день и искажала любую
            // аналитику по датам. Нечитаемая дата — это испорченная строка.
            $orderedAt = $this->parseDate($row['created_at'] ?? null);
            if (null === $orderedAt) {
                $skipped[] = ['reason' => 'unparsable_created_at', 'hint' => $postingNumber];
                continue;
            }

            $mappedItems = $this->mapItems($row);
            if (null !== $mappedItems['error']) {
                $skipped[] = ['reason' => $mappedItems['error'], 'hint' => $postingNumber];
                continue;
            }

            $orders[] = new MappedOrder(
                externalId: $postingNumber,
                scheme: $scheme,
                orderedAt: $orderedAt,
                rawStatus: $status,
                items: $mappedItems['items'],
                externalOrderId: $this->stringOrNull($row['order_number'] ?? null),
                rawSubstatus: $this->stringOrNull($row['substatus'] ?? null),
                attributes: $this->mapAttributes($row),
            );
        }

        return new MappedOrderBatch($orders, $skipped);
    }

    /**
     * Разбор позиций отправления.
     *
     * Повреждённая позиция — тоже потеря, и она не должна быть тише, чем
     * повреждённый заказ: приведение произвольного значения к `0` или к `true`
     * даёт правдоподобную, но выдуманную строку, а raw при этом помечается
     * DONE и курсор уходит вперёд. Поэтому нарушение структуры делает
     * НЕДЕЙСТВИТЕЛЬНЫМ весь posting: половина позиций хуже, чем явная очередь
     * на разбор — по половине нельзя посчитать ни выкуп, ни сумму заказа.
     *
     * @param array<string, mixed> $row
     *
     * @return array{items: list<MappedOrderItem>, error: ?string}
     */
    private function mapItems(array $row): array
    {
        $products = $row['products'] ?? [];
        if (!is_array($products)) {
            return ['items' => [], 'error' => 'malformed_products'];
        }

        $items = [];
        $lineNo = 0;
        $seen = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                return ['items' => [], 'error' => 'malformed_product_entry'];
            }

            $quantity = $this->intOrNull($product['quantity'] ?? null);
            if (null === $quantity || $quantity < 0) {
                return ['items' => [], 'error' => 'malformed_product_quantity'];
            }

            $buyout = $this->boolOrNull($product['is_marketplace_buyout'] ?? null);
            if (null === $buyout) {
                return ['items' => [], 'error' => 'malformed_product_buyout'];
            }

            // Цена может отсутствовать легально, но присутствующая и
            // неразбираемая — испорченная: молчаливый null означал бы
            // «бесплатно» в любом денежном расчёте.
            $rawPrice = $product['price'] ?? null;
            $priceMinor = null === $rawPrice ? null : $this->toMinor($rawPrice);
            if (null !== $rawPrice && null === $priceMinor) {
                return ['items' => [], 'error' => 'malformed_product_price'];
            }

            $sku = $this->stringOrNull($product['sku'] ?? null);
            $offerId = $this->stringOrNull($product['offer_id'] ?? null);
            $name = $this->stringOrNull($product['name'] ?? null);

            $items[] = new MappedOrderItem(
                // lineNo — только порядок отображения.
                lineNo: $lineNo,
                lineKey: $this->lineKey($sku, $offerId, $lineNo, $seen),
                quantity: $quantity,
                externalSku: $sku,
                offerId: $offerId,
                name: $name,
                priceMinor: $priceMinor,
                currency: $this->stringOrNull($product['currency_code'] ?? null),
                marketplaceBuyout: $buyout,
                // Ровно те ключи, которые читает OzonListingResolver.
                sourceData: array_filter([
                    'sku' => $sku,
                    'offer_id' => $offerId,
                    'name' => $name,
                ], static fn (mixed $v): bool => null !== $v),
            );
            ++$lineNo;
        }

        return ['items' => $items, 'error' => null];
    }

    /**
     * Строгое целое: строка "3" допустима, "3.5" и "три" — нет. Приведение
     * произвольного значения к 0 тихо превратило бы позицию в «ничего не
     * заказано».
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && 1 === preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * Строгий bool: строка "false" при приведении становится true, то есть
     * ровно переворачивает признак выкупа.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        if (null === $value) {
            return false;
        }

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapAttributes(array $row): array
    {
        $analytics = $row['analytics_data'] ?? null;
        $analytics = is_array($analytics) ? $analytics : [];

        return array_filter([
            'order_id' => isset($row['order_id']) && is_int($row['order_id']) ? $row['order_id'] : null,
            'cancel_reason_id' => isset($row['cancel_reason_id']) && is_int($row['cancel_reason_id']) ? $row['cancel_reason_id'] : null,
            'delivery_type' => $this->stringOrNull($analytics['delivery_type'] ?? null),
            'warehouse_id' => isset($analytics['warehouse_id']) && is_int($analytics['warehouse_id']) ? $analytics['warehouse_id'] : null,
        ], static fn (mixed $v): bool => null !== $v);
    }

    /**
     * Рубли строкой → копейки строкой. Через float нельзя: денежная арифметика
     * в плавающей точке даёт расхождения на больших объёмах.
     */
    /**
     * Идентичность позиции внутри заказа.
     *
     * SKU (или offerId) плюс номер повторения. Позиционный ключ ломался при
     * перестановке `products`: строка сохраняла прежние опознавательные поля,
     * но получала количество, цену и листинг соседнего товара. Голый SKU тоже
     * не годится — один SKU может повториться на двух строках отправления.
     *
     * @param array<string, int> $seen счётчик повторений, изменяется по ссылке
     */
    private function lineKey(?string $sku, ?string $offerId, int $lineNo, array &$seen): string
    {
        $base = null !== $sku ? 'sku:'.$sku : (null !== $offerId ? 'offer:'.$offerId : null);
        if (null === $base) {
            // Товар без обоих идентификаторов опознать нечем — остаётся
            // позиция. Хуже позиционного ключа тут ничего нет, но и лучше нет.
            return 'line:'.$lineNo;
        }

        $occurrence = $seen[$base] ?? 0;
        $seen[$base] = $occurrence + 1;

        // Длинный идентификатор сворачиваем в хеш, а НЕ обрезаем: обрезка
        // съедала бы номер повторения (#0 и #1 давали один ключ), а два
        // разных offer_id с общим длинным началом схлопывались бы в одну
        // позицию. offer_id у Ozon бывает до 255 символов.
        if (mb_strlen($base) > 80) {
            $base = 'h:'.hash('sha256', $base);
        }

        return $base.'#'.$occurrence;
    }

    private function toMinor(mixed $price): ?string
    {
        if (!is_string($price) && !is_int($price) && !is_float($price)) {
            return null;
        }

        $raw = trim((string) $price);
        if (1 !== preg_match('/^(-?)(\d+)(?:[.,](\d{1,2}))?$/', $raw, $m)) {
            return null;
        }

        $fraction = str_pad($m[3] ?? '', 2, '0');
        $digits = ltrim($m[2].$fraction, '0');

        // Ноль канонизируем без знака: "-0" — то же самое число, но другая
        // строка, и сравнение денежных значений на нём разъезжается.
        return '' === $digits ? '0' : $m[1].$digits;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }

    /**
     * Строгий разбор даты заказа.
     *
     * `new DateTimeImmutable($value)` датой не проверяет ничего: он примет
     * относительную строку («tomorrow»), молча нормализует несуществующее
     * число (2026-02-30 → 2026-03-02) и подставит таймзону процесса там, где
     * её не было. Любой из этих случаев дал бы заказу правдоподобную, но
     * выдуманную дату, а raw при этом пометился бы DONE — потеря стала бы
     * постоянной и незаметной.
     *
     * Поэтому принимаем только явный момент времени со смещением.
     */
    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ('' === $raw) {
            return null;
        }

        // Таймзона задаётся явно: в форматах с литеральным «Z» символ
        // съедается как обычный текст и смещение из строки НЕ читается —
        // без этого аргумента момент в UTC был бы прочитан как местное время
        // и каждая дата заказа уехала бы на смещение сервера.
        $utc = new \DateTimeZone('UTC');

        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw, $utc);
            if (false === $parsed) {
                continue;
            }

            // createFromFormat прощает лишнее и «чинит» невозможные даты:
            // getLastErrors() — единственный способ это заметить.
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $parsed;
        }

        return null;
    }
}
