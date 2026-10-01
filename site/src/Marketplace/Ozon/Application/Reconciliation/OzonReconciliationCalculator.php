<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\CostBucket;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\OzonReconciliationInputs;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\OzonReconciliationResult;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\ReconciledLine;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationBlockMap;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationTolerance;
use App\Shared\Domain\ValueObject\Money;

/**
 * Превращает данные трёх источников в строки сверки. Без БД и без побочных эффектов.
 *
 * Продажи и возвраты: «Реализация» сверяется с сырьём в базе `sale_price`, сырьё — с учётом в базе `sale_amount`
 * (учёт хранит цену продавца, «Реализация» — цену покупателя, напрямую они несопоставимы).
 * Затраты: сырьё против учёта по категориям, плюс итог блока. Источник без данных — `null`, а не ноль.
 */
final readonly class OzonReconciliationCalculator
{
    public function __construct(
        private OzonReconciliationTolerance $tolerance,
        private string $currency = 'RUB',
    ) {
    }

    public function calculate(OzonReconciliationInputs $in): OzonReconciliationResult
    {
        $rawLoaded = $in->rawDaysPresent > 0;
        $partial = $in->rawDaysPresent < $in->rawDaysExpected;
        $coverageNote = $partial
            ? sprintf('Сырые данные загружены за %d из %d дн.', $in->rawDaysPresent, $in->rawDaysExpected)
            : null;
        $noRawNote = 'Сырые данные Ozon за период не загружены';
        $noRealizationNote = 'Отчёт «Реализация» за период не загружен';

        $lines = [];

        // Реализация ↔ сырьё (база sale_price).
        foreach ([
            [OzonReconciliationBlock::SALES, $in->realization?->sales, $in->rawFlows->salesBuyerBase],
            [OzonReconciliationBlock::RETURNS, $in->realization?->returns, $in->rawFlows->returnsBuyerBase],
        ] as [$block, $source, $rawTarget]) {
            $target = $rawLoaded ? $rawTarget : null;
            $lines[] = $this->line(
                OzonReconciliationCheck::REALIZATION_VS_RAW,
                $block,
                '',
                $source,
                $target,
                null,
                null,
                null === $source ? $noRealizationNote : (null === $target ? $noRawNote : $coverageNote),
            );
        }

        // Сырьё ↔ учёт (база sale_amount).
        $lines[] = $this->line(
            OzonReconciliationCheck::RAW_VS_LEDGER,
            OzonReconciliationBlock::SALES,
            '',
            $rawLoaded ? $in->rawFlows->salesSellerBase : null,
            $in->ledgerFlows->sales,
            $rawLoaded ? $in->rawFlows->salesCount : null,
            $in->ledgerFlows->salesCount,
            $rawLoaded ? null : $noRawNote,
        );
        $lines[] = $this->line(
            OzonReconciliationCheck::RAW_VS_LEDGER,
            OzonReconciliationBlock::RETURNS,
            '',
            $rawLoaded ? $in->rawFlows->returnsSellerBase : null,
            $in->ledgerFlows->returns,
            $rawLoaded ? $in->rawFlows->returnsCount : null,
            $in->ledgerFlows->returnsCount,
            $rawLoaded ? null : $noRawNote,
        );

        foreach ($this->costLines($in, $rawLoaded, $noRawNote) as $costLine) {
            $lines[] = $costLine;
        }

        $totals = array_values(array_filter($lines, static fn (ReconciledLine $l): bool => $l->isBlockTotal()));
        $withData = array_values(array_map(
            static fn (ReconciledLine $l): OzonReconciliationStatus => $l->status,
            array_filter($totals, static fn (ReconciledLine $l): bool => OzonReconciliationStatus::NO_DATA !== $l->status),
        ));

        $overall = OzonReconciliationStatus::worstOf($withData);
        // Неполная загрузка сырья не позволяет объявить «сошлось»: сверена только загруженная часть.
        if ($partial && OzonReconciliationStatus::MISMATCH !== $overall) {
            $overall = OzonReconciliationStatus::NO_DATA;
        }

        $mismatches = count(array_filter($totals, static fn (ReconciledLine $l): bool => OzonReconciliationStatus::MISMATCH === $l->status));

        return new OzonReconciliationResult($lines, $overall, $mismatches);
    }

    /**
     * @return list<ReconciledLine>
     */
    private function costLines(OzonReconciliationInputs $in, bool $rawLoaded, string $noRawNote): array
    {
        $zero = new CostBucket(Money::fromMinor(0, $this->currency), 0);
        $codes = array_values(array_unique([...array_keys($in->rawCosts), ...array_keys($in->ledgerCosts)]));
        sort($codes);

        /** @var array<string, array{raw: CostBucket, ledger: CostBucket}> $byBlock */
        $byBlock = [];
        $lines = [];

        foreach ($codes as $code) {
            $raw = $in->rawCosts[$code] ?? $zero;
            $ledger = $in->ledgerCosts[$code] ?? $zero;
            $block = OzonReconciliationBlockMap::forCategoryCode($code);

            $lines[] = $this->line(
                OzonReconciliationCheck::RAW_VS_LEDGER,
                $block,
                $code,
                $rawLoaded ? $raw->net : null,
                $ledger->net,
                $rawLoaded ? $raw->count : null,
                $ledger->count,
                $rawLoaded ? $this->unrecognizedNote($block) : $noRawNote,
            );

            $sum = $byBlock[$block->value] ?? ['raw' => $zero, 'ledger' => $zero];
            $byBlock[$block->value] = ['raw' => $sum['raw']->plus($raw), 'ledger' => $sum['ledger']->plus($ledger)];
        }

        foreach ($byBlock as $blockValue => $sum) {
            $block = OzonReconciliationBlock::from($blockValue);
            $lines[] = $this->line(
                OzonReconciliationCheck::RAW_VS_LEDGER,
                $block,
                '',
                $rawLoaded ? $sum['raw']->net : null,
                $sum['ledger']->net,
                $rawLoaded ? $sum['raw']->count : null,
                $sum['ledger']->count,
                $rawLoaded ? $this->unrecognizedNote($block) : $noRawNote,
            );
        }

        return $lines;
    }

    private function unrecognizedNote(OzonReconciliationBlock $block): ?string
    {
        return OzonReconciliationBlock::UNRECOGNIZED === $block
            ? 'Услуги вне справочника категорий: нужен маппинг'
            : null;
    }

    private function line(
        OzonReconciliationCheck $check,
        OzonReconciliationBlock $block,
        string $categoryCode,
        ?Money $source,
        ?Money $target,
        ?int $sourceCount,
        ?int $targetCount,
        ?string $note,
    ): ReconciledLine {
        return new ReconciledLine(
            $check,
            $block,
            $categoryCode,
            $source,
            $target,
            $this->tolerance->evaluate($source, $target),
            $sourceCount,
            $targetCount,
            $note,
        );
    }
}
