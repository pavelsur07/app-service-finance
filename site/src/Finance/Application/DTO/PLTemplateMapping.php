<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Экран «Сопоставить с шаблоном»: несопоставленные статьи шаблона и сколько статей шаблона уже
 * есть у компании по коду.
 */
final readonly class PLTemplateMapping
{
    /**
     * @param list<PLTemplateMappingRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $matchedCount,
        public int $templateCount,
    ) {
    }
}
