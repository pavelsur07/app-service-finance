<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Facade\FinanceFacade;

/**
 * «Итог года» в списке вариантов (решение Владельца 04.11): чистая прибыль варианта за год по
 * всем парам — строка дерева ОПиУ с кодом `NET_PROFIT`, тем же калькулятором, что отчёт. Все
 * варианты года — одним расчётом: вариант — колонка.
 */
final readonly class BudgetYearTotals
{
    public const string NET_PROFIT_CODE = 'NET_PROFIT';

    public function __construct(
        private BudgetCellsQuery $cells,
        private FinanceFacade $finance,
    ) {
    }

    /**
     * @param list<string> $versionIds варианты одной компании и года
     *
     * @return array<string, string>|null versionId → чистая прибыль; null — в дереве нет строки `NET_PROFIT`
     */
    public function netProfit(string $companyId, int $year, array $versionIds): ?array
    {
        if ([] === $versionIds) {
            return [];
        }
        $amounts = $this->cells->yearTotalsByVersion($companyId, $versionIds);
        $columns = [];
        foreach ($versionIds as $versionId) {
            $columns[] = new PlPreviewColumn($versionId, $amounts[$versionId] ?? []);
        }
        $tree = $this->finance->calculatePlTree(
            $companyId,
            new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
            new \DateTimeImmutable(sprintf('%04d-12-31', $year)),
            $columns,
        );

        foreach ($tree->rows as $row) {
            if (self::NET_PROFIT_CODE !== $row->code) {
                continue;
            }
            $result = [];
            foreach ($versionIds as $versionId) {
                $result[$versionId] = number_format($row->values[$versionId] ?? 0.0, 2, '.', '');
            }

            return $result;
        }

        return null;
    }
}
