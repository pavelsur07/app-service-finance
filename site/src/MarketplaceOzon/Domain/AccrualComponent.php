<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Domain;

use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;

/**
 * Один компонент начисления by-day: одна услуга или одно поле продажи — одна будущая операция.
 */
final readonly class AccrualComponent
{
    public function __construct(
        /** Код Ozon как есть: `ozon:service:32`, `ozon:field:sale_amount`. */
        public string $sourceType,
        public Money $amount,
        /** Ключ факта `SourceFacts`. */
        public string $sourceKey,
        public ?string $sku = null,
        public ?int $quantity = null,
        /** Вид, который задаёт сам переводчик (поля продажи); иначе — из справочника. */
        public ?OperationKind $kind = null,
    ) {
    }
}
