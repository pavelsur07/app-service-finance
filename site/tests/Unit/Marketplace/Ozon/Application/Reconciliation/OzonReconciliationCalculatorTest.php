<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\CostBucket;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\LedgerFlowTotals;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\OzonReconciliationInputs;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\RawFlowTotals;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\RealizationTotals;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\ReconciledLine;
use App\Marketplace\Ozon\Application\Reconciliation\OzonReconciliationCalculator;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationTolerance;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class OzonReconciliationCalculatorTest extends TestCase
{
    public function testEverythingMatches(): void
    {
        $result = $this->calculator()->calculate($this->inputs());

        self::assertSame(OzonReconciliationStatus::MATCHED, $result->overall);
        self::assertSame(0, $result->mismatchCount);
        self::assertSame(OzonReconciliationStatus::MATCHED, $this->find($result->lines, OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationBlock::SALES)->status);
        // Сырьё и учёт сравниваются в базе продавца, «Реализация» с сырьём — в базе покупателя.
        $rawVsLedger = $this->find($result->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::SALES);
        self::assertSame(299900, $rawVsLedger->source?->amountMinor());
        self::assertSame(1, $rawVsLedger->sourceCount);
    }

    public function testLedgerMissingCostMakesMismatchWithBlockTotal(): void
    {
        $in = $this->inputs(ledgerCosts: ['ozon_logistic_direct' => $this->bucket(10000, 2)]);

        $result = $this->calculator()->calculate($in);

        $category = $this->find($result->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct');
        self::assertSame(OzonReconciliationStatus::MISMATCH, $category->status);
        $total = $this->find($result->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::LOGISTICS);
        self::assertSame(-1800, $total->target?->amountMinor() - $total->source?->amountMinor());
        self::assertSame(OzonReconciliationStatus::MISMATCH, $result->overall);
        self::assertSame(1, $result->mismatchCount);
    }

    public function testCostPresentOnlyInLedgerIsMismatchNotSkipped(): void
    {
        $in = $this->inputs(
            rawCosts: [],
            ledgerCosts: ['ozon_logistic_direct' => $this->bucket(5000, 1)],
        );

        $line = $this->find($this->calculator()->calculate($in)->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct');

        self::assertSame(0, $line->source?->amountMinor());
        self::assertSame(OzonReconciliationStatus::MISMATCH, $line->status);
    }

    public function testDifferenceWithinOneRubleIsToleratedButNotMatched(): void
    {
        $in = $this->inputs(ledgerCosts: ['ozon_logistic_direct' => $this->bucket(11800 - 100, 2)]);

        $result = $this->calculator()->calculate($in);

        self::assertSame(
            OzonReconciliationStatus::WITHIN_TOLERANCE,
            $this->find($result->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::LOGISTICS)->status,
        );
        self::assertSame(OzonReconciliationStatus::WITHIN_TOLERANCE, $result->overall);
        self::assertSame(0, $result->mismatchCount);
    }

    public function testNoRealizationGivesNoDataLinesButKeepsOverallFromLedgerCheck(): void
    {
        $result = $this->calculator()->calculate($this->inputs(noRealization: true));

        $line = $this->find($result->lines, OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationBlock::SALES);
        self::assertSame(OzonReconciliationStatus::NO_DATA, $line->status);
        self::assertNull($line->source);
        self::assertStringContainsString('Реализация', (string) $line->note);
        self::assertSame(OzonReconciliationStatus::MATCHED, $result->overall);
    }

    public function testRealizationMismatchIsReported(): void
    {
        $realization = new RealizationTotals($this->money(100000), 1, $this->money(147743), 1);

        $result = $this->calculator()->calculate($this->inputs(realization: $realization));

        self::assertSame(
            OzonReconciliationStatus::MISMATCH,
            $this->find($result->lines, OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationBlock::SALES)->status,
        );
        self::assertSame(1, $result->mismatchCount);
    }

    public function testNoRawDocumentsMakesEverythingNoData(): void
    {
        $result = $this->calculator()->calculate($this->inputs(daysPresent: 0, daysExpected: 0));

        self::assertSame(OzonReconciliationStatus::NO_DATA, $result->overall);
        foreach ($result->lines as $line) {
            self::assertSame(OzonReconciliationStatus::NO_DATA, $line->status, $line->categoryCode.$line->block->value);
            self::assertNotNull($line->note);
        }
    }

    public function testPartialCoverageNeverReportsMatched(): void
    {
        $result = $this->calculator()->calculate($this->inputs(daysPresent: 20, daysExpected: 30));

        self::assertSame(OzonReconciliationStatus::NO_DATA, $result->overall);
        $line = $this->find($result->lines, OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationBlock::SALES);
        self::assertStringContainsString('20 из 30', (string) $line->note);
    }

    public function testPartialCoverageStillSurfacesMismatch(): void
    {
        $result = $this->calculator()->calculate($this->inputs(
            daysPresent: 20,
            daysExpected: 30,
            ledgerCosts: [],
        ));

        self::assertSame(OzonReconciliationStatus::MISMATCH, $result->overall);
    }

    public function testUnrecognizedServicesGetNote(): void
    {
        $in = $this->inputs(
            rawCosts: ['ozon_unknown_99' => $this->bucket(700, 1)],
            ledgerCosts: ['ozon_unknown_99' => $this->bucket(700, 1)],
        );

        $line = $this->find($this->calculator()->calculate($in)->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::UNRECOGNIZED);

        self::assertSame(OzonReconciliationStatus::MATCHED, $line->status);
        self::assertStringContainsString('нужен маппинг', (string) $line->note);
    }

    public function testSignMismatchIsNotHiddenByEqualModules(): void
    {
        $in = $this->inputs(
            rawCosts: ['ozon_compensation' => $this->bucket(-5000, 1)],
            ledgerCosts: ['ozon_compensation' => $this->bucket(5000, 1)],
        );

        $result = $this->calculator()->calculate($in);

        self::assertSame(
            OzonReconciliationStatus::MISMATCH,
            $this->find($result->lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::COMPENSATIONS)->status,
        );
    }

    /**
     * @param array<string, CostBucket>|null $rawCosts
     * @param array<string, CostBucket>|null $ledgerCosts
     */
    private function inputs(
        ?RealizationTotals $realization = null,
        ?array $rawCosts = null,
        ?array $ledgerCosts = null,
        int $daysPresent = 30,
        int $daysExpected = 30,
        bool $noRealization = false,
    ): OzonReconciliationInputs {
        // По умолчанию «Реализация» совпадает с сырьём в базе покупателя.
        $realization = $noRealization
            ? null
            : ($realization ?? new RealizationTotals($this->money(116857), 1, $this->money(147743), 1));

        return new OzonReconciliationInputs(
            $realization,
            new RawFlowTotals($this->money(116857), $this->money(299900), 1, $this->money(147743), $this->money(264700), 1),
            $rawCosts ?? ['ozon_logistic_direct' => $this->bucket(11800, 2)],
            $daysPresent,
            $daysExpected,
            new LedgerFlowTotals($this->money(299900), 1, $this->money(264700), 1),
            $ledgerCosts ?? ['ozon_logistic_direct' => $this->bucket(11800, 2)],
            new CostBucket($this->money(0), 0),
        );
    }

    private function calculator(): OzonReconciliationCalculator
    {
        return new OzonReconciliationCalculator(OzonReconciliationTolerance::oneRuble());
    }

    private function money(int $minor): Money
    {
        return Money::fromMinor($minor, 'RUB');
    }

    private function bucket(int $minor, int $count): CostBucket
    {
        return new CostBucket($this->money($minor), $count);
    }

    /**
     * @param list<ReconciledLine> $lines
     */
    private function find(array $lines, OzonReconciliationCheck $check, OzonReconciliationBlock $block, string $code = ''): ReconciledLine
    {
        foreach ($lines as $line) {
            if ($line->check === $check && $line->block === $block && $line->categoryCode === $code) {
                return $line;
            }
        }

        self::fail(sprintf('Line %s/%s/%s not found.', $check->value, $block->value, $code));
    }
}
