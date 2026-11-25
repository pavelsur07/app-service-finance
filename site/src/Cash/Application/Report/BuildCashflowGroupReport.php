<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowGroupRangeTooLarge;
use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Cash\Infrastructure\Query\CashflowGroupReportQuery;
use App\Cash\Infrastructure\Query\CashflowGroupSnapshot;
use App\Cash\Infrastructure\Query\CashIntragroupQuery;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\ConsolidationGroupFacadeInterface;

/**
 * Консолидированный отчёт ДДС группы компании за период, только чтение. Строки — статьи эталона
 * (дерево головной компании): сумма статьи компании сворачивается к ближайшей статье с кодом
 * (`group_code`); операция прямо на системной статье — в её секцию; статья без кода до системной
 * или код, которого нет в эталоне, — «Не сопоставлено». Внутригрупповая операция — ИНН контрагента
 * равен ИНН другой компании группы. Элиминируются обе ноги сверенной пары в периоде. Только RUB.
 */
final readonly class BuildCashflowGroupReport
{
    public const CURRENCY = 'RUB';
    public const UNMATCHED = 'unmatched';
    public const TOTAL = 'total';

    public function __construct(
        private ConsolidationGroupFacadeInterface $groups,
        private CompanyFacade $companyFacade,
        private CashflowCategoryRepository $categories,
        private CashflowGroupReportQuery $query,
        private BuildCashflowReconciliation $reconcile,
        private CashIntragroupQuery $intragroupQuery,
        private CashflowGroupSnapshot $snapshot,
        private CashFacade $cash,
        private IntragroupGroupAccess $access,
    ) {
    }

    /**
     * null — компания не входит в группу консолидации.
     *
     * @return ($group is null ? CashflowGroupReport|null : CashflowGroupReport|CashflowGroupRangeTooLarge|null)
     */
    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $group = null, ?string $ownerId = null): CashflowGroupReport|CashflowGroupRangeTooLarge|null
    {
        return $this->snapshot->read(function () use ($companyId, $from, $to, $group, $ownerId): CashflowGroupReport|CashflowGroupRangeTooLarge|null {
            $head = $this->groups->headOf($companyId);
            if (null === $head) {
                return null;
            }
            $ids = $this->groups->members($head);
            if ([] === $ids || $ids[0] !== $head || !\in_array($companyId, $ids, true)) {
                throw new \DomainException('Нарушен состав группы консолидации.');
            }
            if (null !== $ownerId) {
                $this->access->assertOwner($ids, $ownerId);
            }
            $projection = null;
            if (null !== $group) {
                $calendar = CashflowGroupCalendar::forRange($from, $to, $group);
                if ($calendar->tooLarge) {
                    return new CashflowGroupRangeTooLarge($from, $to, $calendar->requestedGroup);
                }
                $projection = new CashflowGroupPeriodProjection($calendar);
            }

            return $this->build($ids, $from, $to, $projection);
        });
    }

    /** @param non-empty-list<string> $ids головная компания первой */
    private function build(array $ids, \DateTimeImmutable $from, \DateTimeImmutable $to, ?CashflowGroupPeriodProjection $projection): CashflowGroupReport
    {
        $head = $ids[0];
        /** @var array<string, Company> $companies */
        $companies = [];
        $names = [];
        $withoutInn = [];
        $companyInns = [];
        foreach ($ids as $id) {
            $company = $this->companyFacade->findById($id) ?? throw new \DomainException(sprintf('Компания группы %s не найдена.', $id));
            $companies[$id] = $company;
            $names[$id] = (string) $company->getName();
            $inn = trim((string) $company->getInn());
            $companyInns[$id] = $inn;
            if ('' === $inn) {
                $withoutInn[] = $names[$id];
            }
        }
        $zero = array_fill_keys(array_keys($companies), '0.00');

        /** @var array<string, string> $keyOf categoryId => ключ строки отчёта */
        $keyOf = [];
        $roots = [];
        foreach ($companies as $id => $company) {
            foreach ($this->categories->findTreeByCompany($company) as $category) {
                if ($id === $head && null === $category->getParent()) {
                    $roots[] = $category;
                }
                $keyOf[(string) $category->getId()] = self::key($category);
            }
        }

        $reconciliation = ($this->reconcile)($ids, $from, $to);
        /** @var array<string, CashflowGroupSubtotal> $own */
        $own = [];
        $otherCurrencies = [];
        foreach (($this->query)($ids, $from, $to, $projection?->calendar->group) as $amount) {
            if (self::CURRENCY !== $amount->currency) {
                $otherCurrencies[$amount->currency] ??= $zero;
                $otherCurrencies[$amount->currency][$amount->companyId] = bcadd($otherCurrencies[$amount->currency][$amount->companyId], $amount->amount, 2);
                continue;
            }
            $key = $keyOf[$amount->categoryId] ?? self::UNMATCHED;
            $own[$key] ??= new CashflowGroupSubtotal($zero);
            $own[$key]->amounts[$amount->companyId] = bcadd($own[$key]->amounts[$amount->companyId], $amount->amount, 2);
            if (null !== $amount->periodStart) {
                $projection?->addAmount($key, $amount->periodStart, $amount->amount);
            }
        }
        foreach ($reconciliation->pairs as $outId => $inId) {
            $bucket = $projection?->pairBucket($reconciliation->transactions[$outId], $reconciliation->transactions[$inId]);
            foreach ([$outId, $inId] as $id) {
                $tx = $reconciliation->transactions[$id];
                foreach ($tx->splits as $categoryId => $amount) {
                    $key = $keyOf[$categoryId] ?? self::UNMATCHED;
                    $own[$key] ??= new CashflowGroupSubtotal($zero);
                    $row = $own[$key];
                    $row->pairIds[$outId] = true;
                    $reversal = CashDirection::OUTFLOW === $tx->direction ? $amount : bcsub('0', $amount, 2);
                    $row->elimination = bcadd($row->elimination, $reversal, 2);
                    if (null !== $bucket) {
                        $projection->addAmount($key, $bucket, $reversal);
                    }
                }
            }
        }
        foreach ($reconciliation->unpaired as $tx) {
            $projection?->addUnpaired($tx);
            foreach (array_keys($tx->splits) as $categoryId) {
                $key = $keyOf[$categoryId] ?? self::UNMATCHED;
                $own[$key] ??= new CashflowGroupSubtotal($zero);
                $own[$key]->hasUnpaired = true;
            }
        }

        usort($roots, static fn (CashflowCategory $a, CashflowCategory $b): int => [$a->getSort(), $a->getName()] <=> [$b->getSort(), $b->getName()]);
        [$lines, $unmatched, $totals] = $this->treeRows($roots, $own, $zero);
        $periods = null;
        if (null !== $projection) {
            $firstDay = $from->format('Y-m-d');
            $lastDay = $to->format('Y-m-d');
            foreach ($reconciliation->inTransit as $outId => $inId) {
                $out = $reconciliation->transactions[$outId];
                $day = $out->occurredAt->format('Y-m-d');
                $projection->addTransit($day >= $firstDay && $day <= $lastDay ? $out : $reconciliation->transactions[$inId]);
            }
            $opening = '0.00';
            foreach ($this->cash->getGroupReportOpeningBalances($ids, $from, self::CURRENCY) as $account) {
                $opening = bcadd($opening, $account->openingBalance, 2);
            }
            [$periodRows, $periodUnmatched, $periodTotal] = $this->treeRows($roots, $projection->own, $projection->zero);
            $periods = $projection->result([...$periodRows, $periodUnmatched, $periodTotal], $opening, $totals->total);
        }

        return new CashflowGroupReport(
            $head,
            $from,
            $to,
            $names,
            $lines,
            $unmatched,
            $totals,
            $otherCurrencies,
            $withoutInn,
            $reconciliation,
            $this->intragroupQuery->accounts($ids),
            bcsub($totals->total, $reconciliation->unpairedNet, 2),
            $companyInns,
            $periods,
        );
    }

    /**
     * @param list<CashflowCategory> $roots
     * @param array<string, CashflowGroupSubtotal> $own
     * @param array<string, string> $zero
     *
     * @return array{list<CashflowGroupReportLine>, CashflowGroupReportLine, CashflowGroupReportLine}
     */
    private function treeRows(array $roots, array $own, array $zero): array
    {
        $lines = [];
        $shown = [];
        $grand = new CashflowGroupSubtotal($zero);
        foreach ($roots as $root) {
            $grand->include($this->visit($root, 1, $lines, $shown, $own, $zero));
        }
        $unmatched = new CashflowGroupSubtotal($zero);
        foreach (array_diff_key($own, $shown) as $subtotal) {
            $unmatched->include($subtotal);
        }
        $grand->include($unmatched);
        $totals = $grand->line(self::TOTAL, 'Итого', 1, false);

        return [array_values($lines), $unmatched->line(self::UNMATCHED, 'Не сопоставлено', 1, false), $totals];
    }

    /**
     * Строка статьи головной и её подстатей (пре-ордер); статья головной без кода строки не
     * получает — её подстатьи поднимаются на её уровень.
     *
     * @param array<int, CashflowGroupReportLine> $lines
     * @param array<string, true> $shown
     * @param array<string, CashflowGroupSubtotal> $own
     * @param array<string, string> $zero
     */
    private function visit(CashflowCategory $category, int $level, array &$lines, array &$shown, array $own, array $zero): CashflowGroupSubtotal
    {
        // Строка — только по собственному коду или системной статье: ключ предка не наследуется,
        // иначе подстатья головной без кода повторно взяла бы сумму родителя.
        $key = $category->getGroupCode() ?? ($category->isSystem() && null !== $category->getSystemCode() ? 'sys:'.$category->getSystemCode() : null);
        $subtotal = null !== $key && isset($own[$key]) ? clone $own[$key] : new CashflowGroupSubtotal($zero);
        $position = \count($lines);
        if (null !== $key) {
            $shown[$key] = true;
            $lines[] = $subtotal->line($key, $category->getName(), $level, $category->isSystem());
        }
        foreach ($category->getChildren() as $child) {
            $subtotal->include($this->visit($child, null !== $key ? $level + 1 : $level, $lines, $shown, $own, $zero));
        }
        if (null !== $key) {
            $lines[$position] = $subtotal->line($key, $category->getName(), $level, $category->isSystem());
        }

        return $subtotal;
    }

    /** Ключ строки для статьи компании. */
    private static function key(CashflowCategory $category): string
    {
        for ($node = $category; null !== $node; $node = $node->getParent()) {
            if (null !== $node->getGroupCode()) {
                return $node->getGroupCode();
            }
            if ($node->isSystem() && null !== $node->getSystemCode()) {
                // Прямо на системной статье — её секция; статья без кода под ней — не сопоставлена.
                return $node === $category ? 'sys:'.$node->getSystemCode() : self::UNMATCHED;
            }
        }

        return self::UNMATCHED;
    }
}
