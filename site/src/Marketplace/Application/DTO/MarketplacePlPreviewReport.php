<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Application\DTO\PlPreviewUnplacedAmount;
use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewCostStatus;

/**
 * Предпросмотр ОПиУ по данным маркетплейсов: что покажет ОПиУ, если провести данные периода по
 * текущему маппингу. Значения строк — по ключам колонок.
 */
final readonly class MarketplacePlPreviewReport
{
    /**
     * @param list<MarketplacePlPreviewColumn> $columns
     * @param list<PlPreviewRow> $rows дерево ОПиУ компании
     * @param list<PlPreviewUnplacedAmount> $unplaced суммы на статьях, которые не попадут в отчёт
     * @param list<PlPreviewCostCoverageDTO> $costCoverage затраты периода по категориям и статусам
     * @param list<PlPreviewSalesCoverageDTO> $salesCoverage
     * @param list<string> $warnings
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace,
        public array $columns,
        public array $rows,
        public bool $tagsOverlap,
        public array $unplaced,
        public array $costCoverage,
        public string $costTotal,
        public string $costReconciliationDelta,
        public array $salesCoverage,
        public array $warnings,
    ) {
    }

    /**
     * Строки для показа — одно правило для экрана и xlsx. Пустая строка — та, что во всех колонках
     * показала бы ноль при точности своего формата: KPI 0,3 % пустым не считается.
     *
     * @return list<PlPreviewRow>
     */
    public function visibleRows(bool $showEmptyRows): array
    {
        if ($showEmptyRows) {
            return $this->rows;
        }

        return array_values(array_filter($this->rows, static function (PlPreviewRow $row): bool {
            $halfUnit = match ($row->format) {
                PLValueFormat::PERCENT => 0.0005, // доля, показывается в процентах с одним знаком
                PLValueFormat::RATIO => 0.00005,
                PLValueFormat::MONEY, PLValueFormat::QTY => 0.005,
            };
            foreach ($row->values as $value) {
                if (abs($value) >= $halfUnit) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return list<PlPreviewCostCoverageDTO>
     */
    public function costsWithStatus(PlPreviewCostStatus $status): array
    {
        return array_values(array_filter(
            $this->costCoverage,
            static fn (PlPreviewCostCoverageDTO $row): bool => $row->status === $status,
        ));
    }

    /**
     * @return list<PlPreviewCostCoverageDTO>
     */
    public function unmappedCosts(): array
    {
        return $this->costsWithStatus(PlPreviewCostStatus::UNMAPPED);
    }

    /**
     * @return list<PlPreviewCostCoverageDTO>
     */
    public function excludedCosts(): array
    {
        return $this->costsWithStatus(PlPreviewCostStatus::EXCLUDED);
    }

    /**
     * Нетто затрат по статусу — строки сверки.
     *
     * @return array<string, string> значение PlPreviewCostStatus → сумма
     */
    public function costTotalsByStatus(): array
    {
        $totals = [];
        foreach (PlPreviewCostStatus::cases() as $status) {
            $sum = '0';
            foreach ($this->costsWithStatus($status) as $row) {
                // bcmath_compat объявляет bcadd() без типа результата.
                /** @var numeric-string $sum */
                $sum = bcadd($sum, $row->netAmount, 2);
            }
            $totals[$status->value] = bcadd($sum, '0', 2);
        }

        return $totals;
    }

    /**
     * @return list<PlPreviewSalesCoverageDTO>
     */
    public function salesWithoutMapping(): array
    {
        return array_values(array_filter(
            $this->salesCoverage,
            static fn (PlPreviewSalesCoverageDTO $row): bool => !$row->hasActiveMapping,
        ));
    }
}
