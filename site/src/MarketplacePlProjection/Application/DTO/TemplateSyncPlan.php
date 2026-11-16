<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\DTO;

/** Итог синхронизации правил компании с шаблоном (или план, если запись не просили). */
final readonly class TemplateSyncPlan
{
    /** @param list<string> $missingCodes коды статей шаблона, которых нет листом в дереве компании */
    public function __construct(
        public int $created,
        public int $updated,
        public int $deleted,
        public int $unchanged,
        public int $custom,
        public array $missingCodes,
    ) {
    }
}
