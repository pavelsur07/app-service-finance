<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Domain;

use App\MarketplaceOperations\Enum\OperationKind;

/** Строка шаблона платформы: ключ → код статьи стандартного дерева; `plCode = null` — «исключено». */
final readonly class TemplateRule
{
    public function __construct(
        public ?OperationKind $kind,
        public ?string $channel,
        public ?string $sourceType,
        public ?string $plCode,
    ) {
    }

    public function key(): string
    {
        return self::keyOf($this->kind, $this->channel, $this->sourceType);
    }

    public static function keyOf(?OperationKind $kind, ?string $channel, ?string $sourceType): string
    {
        return ($kind->value ?? '')."\n".($channel ?? '')."\n".($sourceType ?? '');
    }
}
