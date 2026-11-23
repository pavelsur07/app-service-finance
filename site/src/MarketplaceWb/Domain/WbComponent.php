<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Domain;

use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;

/**
 * Один компонент строки отчёта WB — одна будущая операция канона.
 */
final readonly class WbComponent
{
    public function __construct(
        /** Код как есть: `wb:field:sale`, `wb:deduction:{причина}`. */
        public string $sourceType,
        public Money $amount,
        /** Ключ факта `SourceFacts`. */
        public string $sourceKey,
        public ?string $nmId = null,
        /** Размер (`techSize`, пусто → `UNKNOWN`, как у листинга). */
        public ?string $size = null,
        public ?int $quantity = null,
        /** Вид, который задаёт сам переводчик; иначе — справочник и решение администратора. */
        public ?OperationKind $kind = null,
    ) {
    }
}
