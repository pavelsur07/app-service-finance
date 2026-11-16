<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Domain;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Enum\RuleOrigin;

/**
 * Правило компании «операция канона → статья ОПиУ». Ключ — вид (для всех каналов или одного)
 * либо код источника канала; `plCategoryId = null` — «исключено из ОПиУ».
 */
final readonly class Rule
{
    /** `valid_from` базового правила: действует на любой день. */
    public const BASE_DATE = '2000-01-01';

    public function __construct(
        public ?OperationKind $kind,
        public ?string $channel,
        public ?string $sourceType,
        public ?string $plCategoryId,
        public RuleOrigin $origin,
        public string $validFrom,
        public ?int $templateVersion = null,
    ) {
    }

    public function key(): string
    {
        return TemplateRule::keyOf($this->kind, $this->channel, $this->sourceType);
    }
}
