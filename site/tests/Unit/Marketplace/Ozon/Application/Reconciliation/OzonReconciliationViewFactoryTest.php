<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Reconciliation\OzonReconciliationViewFactory;
use App\Tests\Builders\Marketplace\OzonReconciliationLineBuilder;
use App\Tests\Builders\Marketplace\OzonReconciliationRunBuilder;
use PHPUnit\Framework\TestCase;

final class OzonReconciliationViewFactoryTest extends TestCase
{
    public function testGroupsLinesByCheckBlockAndCategory(): void
    {
        $run = OzonReconciliationRunBuilder::aRun()->forMonth(2026, 6)->asMismatch(1)->build();
        $line = OzonReconciliationLineBuilder::aLine();
        $lines = [
            $line->withIndex(1)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::SALES)
                ->withAmounts(299900, 299900, OzonReconciliationStatus::MATCHED)->build(),
            $line->withIndex(2)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::LOGISTICS)
                ->withAmounts(11800, 10000, OzonReconciliationStatus::MISMATCH)->build(),
            $line->withIndex(3)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct')
                ->withAmounts(11800, 10000, OzonReconciliationStatus::MISMATCH)->build(),
            $line->withIndex(4)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::LOGISTICS, 'ozon_delivery')
                ->withAmounts(0, 0, OzonReconciliationStatus::MATCHED)->build(),
            $line->withIndex(5)->forCheck(OzonReconciliationCheck::REALIZATION_VS_RAW)->forBlock(OzonReconciliationBlock::SALES)
                ->withAmounts(null, 116857, OzonReconciliationStatus::NO_DATA)->build(),
        ];

        $view = (new OzonReconciliationViewFactory())->create($run, $lines);

        self::assertSame('2026-06', $view->monthValue);
        self::assertSame('июнь 2026', $view->monthLabel);
        self::assertSame(OzonReconciliationStatus::MISMATCH, $view->status);
        self::assertSame([OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationCheck::RAW_VS_LEDGER], array_map(static fn ($c) => $c->check, $view->checks));

        $ledger = $view->check(OzonReconciliationCheck::RAW_VS_LEDGER);
        self::assertNotNull($ledger);
        self::assertSame([OzonReconciliationBlock::SALES, OzonReconciliationBlock::LOGISTICS], array_map(static fn ($b) => $b->block, $ledger->blocks));

        $logistics = $ledger->blocks[1];
        self::assertSame('118.00', $logistics->source);
        self::assertSame('100.00', $logistics->target);
        self::assertSame('-18.00', $logistics->delta);
        // Расхождение выше совпавшей категории; имя берётся из справочника.
        self::assertSame(['ozon_logistic_direct', 'ozon_delivery'], array_map(static fn ($c) => $c->code, $logistics->categories));
        self::assertNotSame('ozon_logistic_direct', $logistics->categories[0]->name);

        $realization = $view->check(OzonReconciliationCheck::REALIZATION_VS_RAW);
        self::assertNotNull($realization);
        self::assertNull($realization->blocks[0]->source);
        self::assertNull($realization->blocks[0]->delta);
        self::assertSame('1168.57', $realization->blocks[0]->target);
    }

    public function testBlockWithoutTotalLineIsSkipped(): void
    {
        $run = OzonReconciliationRunBuilder::aRun()->build();
        $onlyCategory = OzonReconciliationLineBuilder::aLine()->forBlock(OzonReconciliationBlock::LOGISTICS, 'ozon_delivery')->build();

        $view = (new OzonReconciliationViewFactory())->create($run, [$onlyCategory]);

        self::assertSame([], $view->check(OzonReconciliationCheck::RAW_VS_LEDGER)?->blocks);
    }

    public function testUnknownCategoryKeepsCodeAsName(): void
    {
        $run = OzonReconciliationRunBuilder::aRun()->build();
        $lines = [
            OzonReconciliationLineBuilder::aLine()->withIndex(1)->forBlock(OzonReconciliationBlock::UNRECOGNIZED)->build(),
            OzonReconciliationLineBuilder::aLine()->withIndex(2)->forBlock(OzonReconciliationBlock::UNRECOGNIZED, 'ozon_unknown_99')->build(),
        ];

        $view = (new OzonReconciliationViewFactory())->create($run, $lines);

        self::assertSame('ozon_unknown_99', $view->check(OzonReconciliationCheck::RAW_VS_LEDGER)?->blocks[0]->categories[0]->name);
    }
}
