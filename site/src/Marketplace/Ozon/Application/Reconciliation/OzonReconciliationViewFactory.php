<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Entity\OzonReconciliationLine;
use App\Marketplace\Entity\OzonReconciliationRun;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Reconciliation\View\OzonReconciliationBlockView;
use App\Marketplace\Ozon\Application\Reconciliation\View\OzonReconciliationCategoryView;
use App\Marketplace\Ozon\Application\Reconciliation\View\OzonReconciliationCheckView;
use App\Marketplace\Ozon\Application\Reconciliation\View\OzonReconciliationView;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\Shared\Domain\ValueObject\Money;

/**
 * Снимок сверки → модель для страницы. Без БД: на вход Run и его строки.
 * Порядок: проверки по enum, блоки по enum; категории — сначала расхождения, затем по убыванию |разницы|.
 */
final class OzonReconciliationViewFactory
{
    private const STATUS_ORDER = [
        OzonReconciliationStatus::MISMATCH->value => 0,
        OzonReconciliationStatus::NO_DATA->value => 1,
        OzonReconciliationStatus::WITHIN_TOLERANCE->value => 2,
        OzonReconciliationStatus::MATCHED->value => 3,
    ];

    /**
     * @param list<OzonReconciliationLine> $lines
     */
    public function create(OzonReconciliationRun $run, array $lines): OzonReconciliationView
    {
        $currency = $run->getCurrency();
        $checks = [];

        foreach (OzonReconciliationCheck::cases() as $check) {
            $blocks = [];
            foreach (OzonReconciliationBlock::cases() as $block) {
                $total = null;
                $categories = [];
                foreach ($lines as $line) {
                    if ($line->getCheck() !== $check || $line->getBlock() !== $block) {
                        continue;
                    }
                    if ('' === $line->getCategoryCode()) {
                        $total = $line;
                    } else {
                        $categories[] = $line;
                    }
                }

                if (null === $total) {
                    continue;
                }

                usort($categories, static fn (OzonReconciliationLine $a, OzonReconciliationLine $b): int => [self::STATUS_ORDER[$a->getStatus()->value], -abs($a->getDeltaMinor() ?? 0), $a->getCategoryCode()]
                    <=> [self::STATUS_ORDER[$b->getStatus()->value], -abs($b->getDeltaMinor() ?? 0), $b->getCategoryCode()]);

                $blocks[] = new OzonReconciliationBlockView(
                    $block,
                    $total->getStatus(),
                    $this->decimal($total->getSourceMinor(), $currency),
                    $this->decimal($total->getTargetMinor(), $currency),
                    $this->decimal($total->getDeltaMinor(), $currency),
                    $total->getSourceCount(),
                    $total->getTargetCount(),
                    $total->getNote(),
                    array_map(fn (OzonReconciliationLine $c): OzonReconciliationCategoryView => new OzonReconciliationCategoryView(
                        $c->getCategoryCode(),
                        OzonCostCategory::findByCode($c->getCategoryCode())->name ?? $c->getCategoryCode(),
                        $c->getStatus(),
                        $this->decimal($c->getSourceMinor(), $currency),
                        $this->decimal($c->getTargetMinor(), $currency),
                        $this->decimal($c->getDeltaMinor(), $currency),
                        $c->getSourceCount(),
                        $c->getTargetCount(),
                        $c->getNote(),
                    ), $categories),
                );
            }

            $checks[] = new OzonReconciliationCheckView($check, $blocks);
        }

        $month = ReconciliationMonth::containing($run->getPeriodFrom());

        return new OzonReconciliationView(
            $month->value(),
            $month->label(),
            $run->getOverallStatus(),
            $run->getMismatchCount(),
            $run->getCheckedAt(),
            $run->getRawDaysPresent(),
            $run->getRawDaysExpected(),
            $run->hasRealization(),
            Money::fromMinor($run->getOutsideRawCostsMinor(), $currency)->toDecimalString(),
            $checks,
        );
    }

    private function decimal(?int $minor, string $currency): ?string
    {
        return null === $minor ? null : Money::fromMinor($minor, $currency)->toDecimalString();
    }
}
