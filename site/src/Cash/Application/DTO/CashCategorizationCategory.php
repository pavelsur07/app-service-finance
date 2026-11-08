<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Конечная активная статья ДДС компании во входе задачи: id, путь от корня, направление.
 */
final readonly class CashCategorizationCategory
{
    public function __construct(
        public string $id,
        public string $path,
        /** INFLOW | OUTFLOW | null — любое направление */
        public ?string $operationType,
        /** «Что входит и почему» — описание статьи компании; null — не задано */
        public ?string $description = null,
    ) {
    }
}
