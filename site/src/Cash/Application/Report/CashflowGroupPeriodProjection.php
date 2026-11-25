<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowGroupPeriodColumn;
use App\Cash\Application\DTO\CashflowGroupPeriods;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Enum\Transaction\CashDirection;

/** Раскладывает единый набор оборотов и пар по колонкам выбранного диапазона. */
final class CashflowGroupPeriodProjection
{
    /** @var array<string, CashflowGroupSubtotal> суммы до свёртки по дереву эталона */
    public array $own = [];
    /** @var array<string, string> календарное начало колонки => 0,00 */
    public readonly array $zero;
    /** @var array<string, string> */
    private array $transit;
    /** @var array<string, string> */
    private array $unpaired;
    private int $crossCount = 0;
    private string $crossAmount = '0.00';

    public function __construct(public readonly CashflowGroupCalendar $calendar)
    {
        $zero = [];
        foreach ($calendar->periods as $period) {
            $zero[$calendar->bucketKey($period['start'])] = '0.00';
        }
        $this->zero = $this->transit = $this->unpaired = $zero;
    }

    public function addAmount(string $category, string $bucket, string $amount): void
    {
        $subtotal = $this->own[$category] ??= new CashflowGroupSubtotal($this->zero);
        $subtotal->amounts[$bucket] = bcadd($subtotal->amounts[$bucket], $amount, 2);
    }

    /** Обе ноги в полном диапазоне. Возвращает колонку, в которой элиминируются разбивки. */
    public function pairBucket(IntragroupTransaction $out, IntragroupTransaction $in): ?string
    {
        $outKey = $this->calendar->bucketKey($out->occurredAt);
        $inKey = $this->calendar->bucketKey($in->occurredAt);
        if ($outKey === $inKey) {
            return $outKey;
        }
        ++$this->crossCount;
        $this->crossAmount = bcadd($this->crossAmount, $out->amount->toDecimalString(), 2);
        $this->addTransit($out);
        $this->addTransit($in);

        return null;
    }

    public function addTransit(IntragroupTransaction $tx): void
    {
        $key = $this->calendar->bucketKey($tx->occurredAt);
        if (isset($this->transit[$key])) {
            $this->transit[$key] = bcadd($this->transit[$key], self::signed($tx), 2);
        }
    }

    public function addUnpaired(IntragroupTransaction $tx): void
    {
        $key = $this->calendar->bucketKey($tx->occurredAt);
        if (isset($this->unpaired[$key])) {
            $this->unpaired[$key] = bcadd($this->unpaired[$key], self::signed($tx), 2);
        }
    }

    /** @param list<CashflowGroupReportLine> $rows строки со свёрнутыми по дереву колонками периодов */
    public function result(array $rows, string $opening, string $total): CashflowGroupPeriods
    {
        $columns = [];
        $balance = $opening;
        foreach ($this->calendar->periods as $period) {
            $key = $this->calendar->bucketKey($period['start']);
            $amounts = [];
            foreach ($rows as $row) {
                $amounts[$row->key] = $row->amounts[$key];
            }
            $closing = bcadd($balance, $amounts[BuildCashflowGroupReport::TOTAL], 2);
            $columns[] = new CashflowGroupPeriodColumn(
                $period['start'], $period['end'], $period['label'], $amounts,
                $balance, $closing, $this->transit[$key], $this->unpaired[$key],
                bcsub($amounts[BuildCashflowGroupReport::TOTAL], $this->unpaired[$key], 2),
            );
            $balance = $closing;
        }

        return new CashflowGroupPeriods($this->calendar, $columns, $opening, bcadd($opening, $total, 2), $this->crossCount, $this->crossAmount);
    }

    private static function signed(IntragroupTransaction $tx): string
    {
        return (CashDirection::OUTFLOW === $tx->direction ? $tx->amount->negate() : $tx->amount)->toDecimalString();
    }
}
