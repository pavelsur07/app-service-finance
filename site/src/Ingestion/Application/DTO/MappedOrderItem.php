<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

/**
 * Позиция заказа в нормализованном виде.
 *
 * `lineNo` — индекс позиции в исходном массиве маркетплейса. Он часть ключа
 * идемпотентности, поэтому обязан быть стабильным для одного и того же сырья.
 */
final readonly class MappedOrderItem
{
    /**
     * @param array<string, mixed> $sourceData данные для резолва листинга
     */
    public function __construct(
        public int $lineNo,
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
        Assert::greaterThanEq($quantity, 0);
    }
}
