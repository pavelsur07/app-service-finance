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
            $orderedAt = $this->parseOrderedAt($row, $scheme);
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
        // Отсутствующий или null-евый products — не «заказ без товаров», а
        // испорченный ответ: Ozon всегда присылает список. `?? []` превращал
        // его в корректный пустой заказ, и позиции терялись бесследно.
        // Именно список: json_decode(..., true) отдаёт объект тем же массивом,
        // и непустой объект товаров прошёл бы как список с игнорированными
        // ключами. Пустой `{}` от пустого `[]` таким способом не отличить, но
        // и наблюдаемый результат у них один — ноль позиций.
        if (!array_key_exists('products', $row) || !is_array($row['products']) || !array_is_list($row['products'])) {
            return ['items' => [], 'error' => 'malformed_products'];
        }

        $products = $row['products'];

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
            if (null === $sku && null === $offerId) {
                // Опознать позицию нечем, и падение на позиционный ключ
                // вернуло бы ровно тот дефект, ради которого вводился lineKey.
                return ['items' => [], 'error' => 'missing_product_identity'];
            }

            $name = $this->stringOrNull($product['name'] ?? null);

            $items[] = new MappedOrderItem(
                // lineNo — только порядок отображения.
                lineNo: $lineNo,
                lineKey: $this->lineKey($sku, $offerId, $seen),
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
     * Пара `sku:<sku>|offer:<offerId>` плюс номер повторения этой пары.
     * Позиционный ключ ломался при перестановке `products`: строка сохраняла
     * прежние опознавательные поля, но получала количество, цену и листинг
     * соседнего товара. Голый SKU тоже не годится — один SKU может
     * повториться на двух строках отправления, а две строки с одним SKU и
     * разными offer_id вообще разные предложения.
     *
     * @param array<string, int> $seen счётчик повторений, изменяется по ссылке
     */
    private function lineKey(?string $sku, ?string $offerId, array &$seen): string
    {
        // База строится из ОБОИХ идентификаторов, а не из одного sku.
        //
        // Две строки с одинаковым SKU и разными offer_id — это разные товарные
        // предложения. Ключ только по sku давал бы им sku:X#0 и sku:X#1,
        // то есть снова позиционное различение: после перестановки строк ключи
        // менялись бы местами, и цена с количеством уезжали бы к чужому
        // offer_id. Номер повторения применяется только к полностью
        // одинаковой паре.
        //
        // Позиция без обоих идентификаторов сюда не доходит: mapItems
        // отклоняет весь posting раньше.
        $base = 'sku:'.($sku ?? '').'|offer:'.($offerId ?? '');

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

    /**
     * Цена в минорные единицы.
     *
     * Денежная арифметика — то место, где тихая ошибка дороже всего, поэтому
     * разбор строгий: подходит только явная запись числа с не более чем двумя
     * знаками после разделителя. Ноль канонизируется без знака.
     */
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

        if (!$this->fitsBigint($digits, '-' === $m[1])) {
            return null;
        }

        // Ноль канонизируем без знака: «-0» — то же число, но другая строка.
        return '' === $digits ? '0' : $m[1].$digits;
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

    /**
     * Время заказа с учётом различий схем.
     *
     * У FBO в выгрузке есть и `created_at`, и `in_process_at`. Про FBS
     * достоверных данных нет: снятое окно вернуло ноль отправлений, а по
     * документации у него фигурирует `in_process_at`. Поэтому поле не
     * угадывается: для каждой схемы задан порядок предпочтения, и второй
     * вариант служит запасным. Ошибка в предположении о наличии поля стоила
     * бы потери ВСЕХ заказов схемы — они получали бы
     * `unparsable_created_at`, сырьё помечалось бы обработанным, а курсор
     * уходил бы вперёд.
     *
     * @param array<string, mixed> $row
     */
    private function parseOrderedAt(array $row, IngestOrderScheme $scheme): ?\DateTimeImmutable
    {
        $fields = IngestOrderScheme::FBS === $scheme
            ? ['in_process_at', 'created_at']
            : ['created_at', 'in_process_at'];

        foreach ($fields as $field) {
            // Запасное поле — только на случай ОТСУТСТВИЯ предпочтительного.
            //
            // Переход по любой ошибке разбора обходил бы строгую проверку:
            // FBO с испорченным created_at и валидным in_process_at принялся
            // бы с семантически другой датой, а нарушение контракта осталось
            // бы незамеченным.
            if (!array_key_exists($field, $row) || null === $row[$field]) {
                continue;
            }

            return $this->parseDate($row[$field]);
        }

        return null;
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

            // Приводим к UTC явно: для форматов со смещением аргумент $utc
            // игнорируется, и объект остаётся в исходной зоне. Doctrine пишет
            // datetime_immutable в TIMESTAMP WITHOUT TIME ZONE как есть, то
            // есть 10:00+03:00 сохранился бы как 10:00 UTC — сдвиг на три часа
            // в дате заказа и во всей аналитике по ней.
            return $parsed->setTimezone($utc);
        }

        return null;
    }
}
