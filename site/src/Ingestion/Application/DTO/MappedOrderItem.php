<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

/**
 * Позиция заказа в нормализованном виде.
 *
 * `lineNo` — порядок отображения, индекс позиции в исходном массиве.
 * `lineKey` — идентичность позиции внутри заказа. Разделены намеренно: порядок
 * в ответе источника может измениться, и позиционный ключ тогда перемешал бы
 * данные соседних позиций между собой.
 */
final readonly class MappedOrderItem
{
    /**
     * @param array<string, mixed> $sourceData данные для резолва листинга
     */
    public function __construct(
        public int $lineNo,
        public string $lineKey,
        public int $quantity,
        public ?string $externalSku = null,
        public ?string $offerId = null,
        public ?string $barcode = null,
        public ?string $name = null,
        public ?string $priceMinor = null,
        public ?string $currency = null,
        public bool $marketplaceBuyout = false,
        public array $sourceData = [],
    ) {
        Assert::greaterThanEq($lineNo, 0);
        Assert::stringNotEmpty($lineKey);
        Assert::maxLength($lineKey, 120);
        Assert::greaterThanEq($quantity, 0);
    }
}
