<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\DTO;

use App\MarketplacePlProjection\Enum\ProjectionStatus;

/**
 * Проекция ОПиУ из канона за период. Каждая копейка канона — ровно в одной строке:
 * Σ canon = Σ mapped + Σ excluded + Σ unmapped.
 */
final readonly class ProjectionReport
{
    /** @param list<ProjectionLine> $lines */
    public function __construct(public array $lines)
    {
    }

    /** @return array<string, string> plCategoryId → сумма в рублях в знаке строки документа `marketplace_pl` */
    public function amountsByCategory(): array
    {
        $minor = [];
        foreach ($this->lines as $line) {
            if (null !== $line->plCategoryId) {
                $minor[$line->plCategoryId] = ($minor[$line->plCategoryId] ?? 0) + $line->amountMinor;
            }
        }

        return array_map(static fn (int $amount): string => bcdiv((string) $amount, '100', 2), $minor);
    }

    /** @return list<ProjectionLine> */
    public function linesOf(ProjectionStatus $status): array
    {
        return array_values(array_filter($this->lines, static fn (ProjectionLine $l): bool => $status === $l->status && 0 !== $l->amountMinor));
    }

    public function totalMinor(?ProjectionStatus $status = null): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if (null === $status || $status === $line->status) {
                $total += $line->amountMinor;
            }
        }

        return $total;
    }
}
