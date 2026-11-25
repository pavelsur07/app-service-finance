<?php

declare(strict_types=1);

namespace App\Report\Cashflow;

use App\Cash\Application\DTO\CashflowCategoryNode;
use App\Cash\Application\DTO\CashflowReportRow;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Service\ReportPeriodCalendar;

final class CashflowReportBuilder
{
    private const NO_PROJECT_KEY = '__no_project__';
    private const NO_RESPONSIBILITY_CENTER_KEY = '__no_responsibility_center__';

    public function __construct(
        private CashFacade $cashFacade,
        private ProjectDirectionRepository $projectDirectionRepository,
    ) {
    }

    /** Возвращает payload в том же формате, что и раньше */
    public function build(CashflowReportParams $params): array
    {
        $company = $params->company;
        $group = $params->group;
        $from = $params->from;
        $to = $params->to;

        $periods = ReportPeriodCalendar::periods($from, $to, $group);
        $periodCount = count($periods);

        $companyId = (string) $company->getId();
        $categories = $this->cashFacade->getCashflowCategoryTree($companyId);
        if ($params->isDashboardReconciliation()) {
            $categories = array_values(array_filter(
                $categories,
                fn (CashflowCategoryNode $category): bool => $this->categoryMatchesDashboardScope($category, $params),
            ));
        }
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->id] = [
                'entity' => $cat,
                'totals' => [],
            ];
        }

        $projectDirectionIds = null === $params->projectDirectionIds
            ? null
            : $this->expandProjectDirectionIds(
                $params->projectDirectionIds,
                $company,
                $params->availableProjectDirections,
            );
        $responsibilityCenterIds = $params->responsibilityCenterIds;
        $filtersActive = $params->isDashboardReconciliation()
            || null !== $projectDirectionIds
            || null !== $responsibilityCenterIds
            || null !== $params->responsibilityCenterId;
        $emptyFilter = [] === $projectDirectionIds || [] === $responsibilityCenterIds;

        if ($emptyFilter) {
            $rows = [];
        } else {
            $rows = $this->transactionRows($companyId, $from, $to, new CashflowReportRowFilter(
                dashboardScope: $params->isDashboardReconciliation(),
                dashboardCurrency: $params->dashboardCurrency?->value,
                dashboardFlowKind: $params->dashboardFlowKind(),
                projectDirectionIds: $projectDirectionIds,
                responsibilityCenterIds: $responsibilityCenterIds,
                responsibilityCenterId: $params->responsibilityCenterId,
            ));
        }

        if ($filtersActive) {
            $companyRows = $this->transactionRows($companyId, $from, $to, new CashflowReportRowFilter(
                currency: $params->isDashboardReconciliation() ? $params->dashboardCurrency?->value : null,
            ));
        } else {
            $companyRows = $rows;
        }

        foreach ($rows as $row) {
            $catId = $row['category'];
            if (!$catId || !isset($categoryMap[$catId])) {
                continue;
            }

            $amount = $this->signedAmount($row);
            $currency = $row['currency'];
            $periodIndex = $this->findPeriodIndex($periods, $row['occurredAt']);
            if (null === $periodIndex) {
                continue;
            }

            if (!isset($categoryMap[$catId]['totals'][$currency])) {
                $categoryMap[$catId]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
            }

            $categoryMap[$catId]['totals'][$currency][$periodIndex] += $amount;
        }

        $companyTotals = [];
        foreach ($companyRows as $row) {
            $catId = $row['category'];
            if (!$catId || (!$params->isDashboardReconciliation() && !isset($categoryMap[$catId]))) {
                continue;
            }

            $amount = $this->signedAmount($row);
            $currency = $row['currency'];
            $periodIndex = $this->findPeriodIndex($periods, $row['occurredAt']);
            if (null === $periodIndex) {
                continue;
            }

            $companyTotals[$currency][$periodIndex] = ($companyTotals[$currency][$periodIndex] ?? 0) + $amount;
        }

        foreach (array_reverse($categories) as $cat) {
            $parentId = $cat->parentId;
            if (null !== $parentId && isset($categoryMap[$parentId])) {
                $childTotals = $categoryMap[$cat->id]['totals'];
                foreach ($childTotals as $currency => $vals) {
                    if (!isset($categoryMap[$parentId]['totals'][$currency])) {
                        $categoryMap[$parentId]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
                    }

                    foreach ($vals as $idx => $val) {
                        $categoryMap[$parentId]['totals'][$currency][$idx] += $val;
                    }
                }
            }
        }

        $rootCategories = [];
        foreach ($categories as $cat) {
            if (null === $cat->parentId) {
                $rootCategories[] = $cat;
            }
        }

        $categoryTree = $this->buildCategoryTree($categories);

        $accountOpenings = $this->cashFacade->getReportOpeningBalances(
            $companyId,
            $from,
            $params->isDashboardReconciliation() ? $params->dashboardCurrency?->value : null,
        );
        $openingByCurrency = [];
        foreach ($accountOpenings as $accountOpening) {
            $opening = (float) $accountOpening->openingBalance;
            $currency = $accountOpening->currency;
            $openingByCurrency[$currency] = ($openingByCurrency[$currency] ?? 0) + $opening;
        }

        $openings = [];
        $closings = [];
        $currencies = array_unique(array_merge(array_keys($openingByCurrency), array_keys($companyTotals)));
        foreach ($currencies as $currency) {
            $opening = $openingByCurrency[$currency] ?? 0.0;
            $openings[$currency] = [];
            $closings[$currency] = [];
            $current = $opening;
            for ($i = 0; $i < $periodCount; ++$i) {
                $openings[$currency][$i] = $current;
                $net = $companyTotals[$currency][$i] ?? 0;
                $current += $net;
                $closings[$currency][$i] = $current;
            }
        }

        $tree = $this->buildCategoryTotalsTree($categories, $categoryMap);

        $dashboardReconciliation = null;
        if ($params->isDashboardReconciliation()) {
            $dashboardReconciliation = $this->buildDashboardReconciliation($params);
        }

        // Базовые параметры перехода в «Транзакции ДДС» — тот же охват строк, что посчитан выше
        // (проекты уже с раскрытыми дочерними). Режим сверки исключает переводы и технические
        // статьи, список так не фильтрует — там ссылок нет.
        $drilldownCenterIds = $responsibilityCenterIds
            ?? (null === $params->responsibilityCenterId ? null : [$params->responsibilityCenterId]);
        $drilldown = $params->isDashboardReconciliation() ? null : array_filter([
            'projectIds' => null === $projectDirectionIds ? null : implode(',', $projectDirectionIds),
            'centerIds' => null === $drilldownCenterIds ? null : implode(',', $drilldownCenterIds),
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        return [
            'company' => $company,
            'group' => $group,
            'responsibility_center_id' => $params->responsibilityCenterId,
            'project_direction_ids' => $params->projectDirectionIds,
            'responsibility_center_ids' => $params->responsibilityCenterIds,
            'date_from' => $from,
            'date_to' => $to,
            'periods' => $periods,
            'categories' => $rootCategories,
            'categoryTotals' => $categoryMap,
            'openings' => $openings,
            'closings' => $closings,
            'tree' => $tree,
            'categoryTree' => $categoryTree,
            'projectCenterMatrix' => $this->buildProjectCenterMatrix($rows, $periods),
            'dashboardReconciliation' => $dashboardReconciliation,
            'drilldown' => $drilldown,
        ];
    }

    /**
     * @param list<string> $selectedIds
     * @param list<ProjectDirection>|null $availableProjectDirections
     *
     * @return list<string>
     */
    private function expandProjectDirectionIds(
        array $selectedIds,
        Company $company,
        ?array $availableProjectDirections,
    ): array {
        if ([] === $selectedIds) {
            return [];
        }

        $available = [];
        $children = [];
        $projects = $availableProjectDirections ?? $this->projectDirectionRepository->findByCompany($company);
        foreach ($projects as $project) {
            if ($project->getCompany()->getId() !== $company->getId()) {
                continue;
            }

            $projectId = (string) $project->getId();
            $available[$projectId] = true;
            $parentId = $project->getParent()?->getId();
            if (null !== $parentId) {
                $children[(string) $parentId][] = $projectId;
            }
        }

        $expanded = [];
        $collect = static function (string $projectId) use (&$collect, &$expanded, $available, $children): void {
            if (!isset($available[$projectId]) || isset($expanded[$projectId])) {
                return;
            }

            $expanded[$projectId] = $projectId;
            foreach ($children[$projectId] ?? [] as $childId) {
                $collect($childId);
            }
        };
        foreach ($selectedIds as $selectedId) {
            $collect($selectedId);
        }

        return array_values($expanded);
    }

    /**
     * Строки отчёта в прежнем виде массива — ниже по коду отчёт работает с ним.
     *
     * @return list<array<string, mixed>>
     */
    private function transactionRows(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, CashflowReportRowFilter $filter): array
    {
        return array_map(static fn (CashflowReportRow $row): array => [
            'category' => $row->categoryId,
            'project_id' => $row->projectId,
            'project_name' => $row->projectName,
            'responsibility_center_id' => $row->responsibilityCenterId,
            'direction' => $row->direction,
            'amount' => $row->amount,
            'currency' => $row->currency,
            'occurredAt' => $row->occurredAt,
        ], $this->cashFacade->listCashflowReportRows($companyId, $from, $to, $filter));
    }

    /**
     * Presentation categories mirror the same aggregator scope as the dashboard row filter.
     */
    private function categoryMatchesDashboardScope(
        CashflowCategoryNode $category,
        CashflowReportParams $params,
    ): bool {
        if (CashflowFlowKind::TECHNICAL === $category->flowKind) {
            return false;
        }

        $flowKind = $params->dashboardFlowKind();
        if (null === $flowKind) {
            return true;
        }

        return $flowKind === $category->flowKind
            && !\in_array($category->systemCode, [
                CashflowCategory::CODE_UNALLOCATED,
                CashflowCategory::SYSTEM_UNALLOCATED,
            ], true);
    }

    /** @return array{mode:string,activity:string,currency:string,inflow:string,outflow:string,net:string} */
    private function buildDashboardReconciliation(CashflowReportParams $params): array
    {
        $currency = $params->dashboardCurrency ?? FiatCurrency::RUB;
        $turnover = $this->cashFacade->sumGrossTurnoverExcludingTransfers(
            (string) $params->company->getId(),
            $params->from,
            $params->to,
            $currency->value,
            $params->dashboardFlowKind(),
        );
        $inflow = bcadd($turnover['inflow'], '0', $currency->scale());
        $outflow = bcadd($turnover['outflow'], '0', $currency->scale());

        return [
            'mode' => 'dashboard',
            'activity' => $params->dashboardActivity ?? 'all',
            'currency' => $currency->value,
            'inflow' => $inflow,
            'outflow' => $outflow,
            'net' => bcsub($inflow, $outflow, $currency->scale()),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array{label:string,start:\DateTimeInterface,end:\DateTimeInterface}> $periods
     *
     * @return array{
     *     currencies:list<string>,
     *     rowsByCenter:list<array<string,mixed>>,
     *     rowsByProject:list<array<string,mixed>>
     * }
     */
    private function buildProjectCenterMatrix(array $rows, array $periods): array
    {
        $periodCount = count($periods);
        $pairs = [];
        $currencies = [];

        foreach ($rows as $row) {
            $periodIndex = $this->findPeriodIndex($periods, $row['occurredAt']);
            if (null === $periodIndex) {
                continue;
            }

            $projectId = $row['project_id'] ?? null;
            $projectKey = null === $projectId ? self::NO_PROJECT_KEY : (string) $projectId;
            $centerId = $row['responsibility_center_id'] ?? null;
            $centerKey = null === $centerId ? self::NO_RESPONSIBILITY_CENTER_KEY : (string) $centerId;
            $pairKey = $centerKey.'|'.$projectKey;
            $currency = (string) $row['currency'];
            $currencies[$currency] = $currency;

            if (!isset($pairs[$pairKey])) {
                $pairs[$pairKey] = [
                    'project_key' => $projectKey,
                    'project_id' => null === $projectId ? null : (string) $projectId,
                    'project_name' => $row['project_name'] ?? 'Без проекта',
                    'responsibility_center_key' => $centerKey,
                    'responsibility_center_id' => null === $centerId ? null : (string) $centerId,
                    'responsibility_center_name' => null === $centerId ? 'Не задано' : null,
                    'totals' => [],
                ];
            }

            if (!isset($pairs[$pairKey]['totals'][$currency])) {
                $pairs[$pairKey]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
            }

            $pairs[$pairKey]['totals'][$currency][$periodIndex] += $this->signedAmount($row);
        }

        $rowsByCenter = array_values($pairs);
        usort(
            $rowsByCenter,
            static fn (array $a, array $b): int => [
                $a['responsibility_center_name'] ?? $a['responsibility_center_id'] ?? '',
                $a['project_name'],
            ] <=> [
                $b['responsibility_center_name'] ?? $b['responsibility_center_id'] ?? '',
                $b['project_name'],
            ],
        );

        $rowsByProject = $rowsByCenter;
        usort(
            $rowsByProject,
            static fn (array $a, array $b): int => [
                $a['project_name'],
                $a['responsibility_center_name'] ?? $a['responsibility_center_id'] ?? '',
            ] <=> [
                $b['project_name'],
                $b['responsibility_center_name'] ?? $b['responsibility_center_id'] ?? '',
            ],
        );

        return [
            'currencies' => array_values($currencies),
            'rowsByCenter' => $rowsByCenter,
            'rowsByProject' => $rowsByProject,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function signedAmount(array $row): float
    {
        $direction = $row['direction'] instanceof CashDirection
            ? $row['direction']->value
            : $row['direction'];

        return $direction === CashDirection::OUTFLOW->value
            ? -abs((float) $row['amount'])
            : abs((float) $row['amount']);
    }

    /**
     * @param list<CashflowCategoryNode> $categories // список в порядке обхода дерева (getCashflowCategoryTree)
     *
     * @return array<int, array{id:string,name:string,parentId:?string,level:int,order:int}>
     */
    private function buildCategoryTree(array $categories): array
    {
        $result = [];
        $order = 0;

        // Подготовим быстрый доступ по id
        $byId = [];
        foreach ($categories as $c) {
            $byId[$c->id] = $c;
        }

        // Плоский список в текущем порядке (ожидается depth-first из репозитория)
        foreach ($categories as $c) {
            // Число предков, не больше 4 (итого 5 уровней: 0..4); у корня level = 1.
            $level = min($c->level - 1, 4);

            $result[] = [
                'id' => $c->id,
                'name' => $c->name,
                'parentId' => null !== $c->parentId && isset($byId[$c->parentId])
                    ? $c->parentId
                    : null,
                'level' => $level,
                'order' => $order++,
            ];
        }

        return $result;
    }

    /**
     * Собирает иерархию категорий с их суммами по периодам (из $categoryMap['totals']).
     * Формат узла:
     * [
     *   'id'      => string,
     *   'name'    => string,
     *   'level'   => int,   // 0..4
     *   'totals'  => array, // ['RUB' => [..по периодам..], ...]
     *   'children'=> array<node>
     * ].
     *
     * @param list<CashflowCategoryNode> $allCategories // список в порядке обхода дерева
     * @param array<string,array{entity:CashflowCategoryNode, totals:array<string,array<int,float>>}> $categoryMap
     *
     * @return array<int,array>
     */
    private function buildCategoryTotalsTree(array $allCategories, array $categoryMap): array
    {
        // Индексы
        $byId = [];
        foreach ($allCategories as $cat) {
            $byId[$cat->id] = $cat;
        }

        $children = [];
        foreach ($allCategories as $cat) {
            $id = $cat->id;
            $pid = $cat->parentId;
            if (null !== $pid && !isset($byId[$pid])) {
                $pid = null;
            }
            $children[$pid][] = $id; // pid=null → корни
        }

        // Рекурсивный сбор узла
        $makeNode = static function (string $id, int $level) use (&$makeNode, $children, $byId, $categoryMap): array {
            $cat = $byId[$id];
            // уровень ограничим 0..4
            $lvl = max(0, min(4, $level));
            $totals = $categoryMap[$id]['totals'] ?? [];

            $node = [
                'id' => $id,
                'name' => $cat->name,
                'level' => $lvl,
                'totals' => $totals,    // уже агрегировано (с учётом детей — см. логику выше в build)
                'children' => [],
            ];

            foreach ($children[$id] ?? [] as $childId) {
                $node['children'][] = $makeNode($childId, $lvl + 1);
            }

            return $node;
        };

        // Корни в исходном порядке (как пришли из репозитория)
        $tree = [];
        foreach ($children[null] ?? [] as $rootId) {
            $tree[] = $makeNode($rootId, 0);
        }

        return $tree;
    }

    private function findPeriodIndex(array $periods, \DateTimeInterface $date): ?int
    {
        foreach ($periods as $idx => $p) {
            if ($date >= $p['start'] && $date <= $p['end']) {
                return $idx;
            }
        }

        return null;
    }
}
