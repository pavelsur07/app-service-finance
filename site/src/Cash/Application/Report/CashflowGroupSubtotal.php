<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowGroupReportLine;

/** Суммы статьи со всеми видимыми подстатьями и состав её ячейки элиминации. */
final class CashflowGroupSubtotal
{
    public string $elimination = '0.00';
    public bool $hasUnpaired = false;
    /** @var array<string, true> */
    public array $pairIds = [];

    /** @param array<string, string> $amounts */
    public function __construct(public array $amounts)
    {
    }

    public function include(self $child): void
    {
        foreach ($child->amounts as $id => $amount) {
            $this->amounts[$id] = bcadd($this->amounts[$id], $amount, 2);
        }
        $this->elimination = bcadd($this->elimination, $child->elimination, 2);
        $this->hasUnpaired = $this->hasUnpaired || $child->hasUnpaired;
        $this->pairIds += $child->pairIds;
    }

    public function line(string $key, string $name, int $level, bool $section): CashflowGroupReportLine
    {
        $total = $this->elimination;
        foreach ($this->amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return new CashflowGroupReportLine($key, $name, $level, $section, $this->amounts, $this->elimination, $total, $this->hasUnpaired, array_keys($this->pairIds));
    }
}
