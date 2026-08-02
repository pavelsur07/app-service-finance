<?php

namespace App\Report\Cashflow;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\QueryBuilder;

final class CashflowReportBuilder
{
    private const NO_PROJECT_KEY = '__no_project__';
    private const NO_RESPONSIBILITY_CENTER_KEY = '__no_responsibility_center__';

    public function __construct(
        private CashflowCategoryRepository $categoryRepository,
        private CashTransactionRepository $transactionRepository,
        private MoneyAccountRepository $accountRepository,
        private MoneyAccountDailyBalanceRepository $balanceRepository,
    ) {
    }

    /** Возвращает payload в том же формате, что и раньше */
    public function build(CashflowReportParams $params): array
    {
        $company = $params->company;
        $group = $params->group;
        $from = $params->from;
        $to = $params->to;

        $periods = $this->buildPeriods($from, $to, $group);
        $periodCount = count($periods);

        $categories = $this->categoryRepository->findTreeByCompany($company);
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->getId()] = [
                'entity' => $cat,
                'totals' => [],
            ];
        }

        $transactionQueryBuilder = $this->createTransactionRowsQueryBuilder($company, $from, $to);

        if (null !== $params->responsibilityCenterId) {
            $transactionQueryBuilder
                ->andWhere('t.responsibilityCenterId = :responsibilityCenterId')
                ->setParameter('responsibilityCenterId', $params->responsibilityCenterId);
        }

        $rows = $transactionQueryBuilder
            ->getQuery()
            ->getArrayResult();
        $companyRows = null === $params->responsibilityCenterId
            ? $rows
            : $this->createTransactionRowsQueryBuilder($company, $from, $to)
                ->getQuery()
                ->getArrayResult();

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
            if (!$catId || !isset($categoryMap[$catId])) {
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
            $parent = $cat->getParent();
            if ($parent && isset($categoryMap[$parent->getId()])) {
                $childTotals = $categoryMap[$cat->getId()]['totals'];
                foreach ($childTotals as $currency => $vals) {
                    if (!isset($categoryMap[$parent->getId()]['totals'][$currency])) {
                        $categoryMap[$parent->getId()]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
                    }

                    foreach ($vals as $idx => $val) {
                        $categoryMap[$parent->getId()]['totals'][$currency][$idx] += $val;
                    }
                }
            }
        }

        $rootCategories = [];
        foreach ($categories as $cat) {
            if (!$cat->getParent()) {
                $rootCategories[] = $cat;
            }
        }

        $categoryTree = $this->buildCategoryTree($categories);

        $accounts = $this->accountRepository->findBy(['company' => $company]);
        $openingByCurrency = [];
        foreach ($accounts as $account) {
            $date = $from->setTime(0, 0);
            $snapshot = $this->balanceRepository->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'date' => $date,
            ]);

            if ($snapshot) {
                $opening = (float) $snapshot->getOpeningBalance();
            } else {
                $prev = $this->balanceRepository->findLastBefore($company, $account, $from);
                if ($prev) {
                    $opening = (float) $prev->getClosingBalance();
                } else {
                    $opening = (float) $account->getOpeningBalance();
                }
            }

            $currency = $account->getCurrency();
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

        return [
            'company' => $company,
            'group' => $group,
            'responsibility_center_id' => $params->responsibilityCenterId,
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
        ];
    }

    private function createTransactionRowsQueryBuilder(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): QueryBuilder {
        return $this->transactionRepository->createQueryBuilder('t')
            ->select(
                'IDENTITY(split.cashflowCategory) AS category',
                'IDENTITY(t.projectDirection) AS project_id',
                'project.name AS project_name',
                't.responsibilityCenterId AS responsibility_center_id',
                't.direction',
                'split.amount',
                't.currency',
                't.occurredAt',
            )
            // LEFT JOIN, а не INNER: транзакция без категории строк не имеет и в отчёт
            // не попадает по проверке категории ниже — ровно как раньше по пустой колонке.
            ->leftJoin('t.splits', 'split')
            ->leftJoin('t.projectDirection', 'project')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59));
    }

    /**
     * @param list<array<string,mixed>>                                                 $rows
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
     * @param \App\Cash\Entity\Transaction\CashflowCategory[] $categories // полный список, как вернул findTreeByCompany()
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
            $byId[$c->getId()] = $c;
        }

        // Плоский список в текущем порядке (ожидается depth-first из репозитория)
        foreach ($categories as $c) {
            $level = 0;
            $p = $c->getParent();
            // Считаем уровень до 4 (итого 5 уровней: 0..4)
            while ($p && $level < 4) {
                ++$level;
                $p = $p->getParent();
            }

            $result[] = [
                'id' => $c->getId(),
                'name' => (string) $c->getName(),
                'parentId' => $c->getParent() ? $c->getParent()->getId() : null,
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
     * @param \App\Cash\Entity\Transaction\CashflowCategory[]                                                                  $allCategories // полный список (findTreeByCompany)
     * @param array<string,array{entity:\App\Cash\Entity\Transaction\CashflowCategory, totals:array<string,array<int,float>>}> $categoryMap
     *
     * @return array<int,array>
     */
    private function buildCategoryTotalsTree(array $allCategories, array $categoryMap): array
    {
        // Индексы
        $byId = [];
        $children = [];
        foreach ($allCategories as $cat) {
            $id = $cat->getId();
            $byId[$id] = $cat;
            $pid = $cat->getParent() ? $cat->getParent()->getId() : null;
            $children[$pid][] = $id; // pid=null → корни
        }

        // Рекурсивный сбор узла
        $makeNode = function (string $id, int $level) use (&$makeNode, $children, $byId, $categoryMap): array {
            $cat = $byId[$id];
            // уровень ограничим 0..4
            $lvl = max(0, min(4, $level));
            $totals = $categoryMap[$id]['totals'] ?? [];

            $node = [
                'id' => $id,
                'name' => (string) $cat->getName(),
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

    private function buildPeriods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $group): array
    {
        $periods = [];
        $current = $from;
        while ($current <= $to) {
            switch ($group) {
                case 'day':
                    $start = $current->setTime(0, 0);
                    $end = $current->setTime(23, 59, 59);
                    $label = $current->format('d.m.Y');
                    $current = $current->modify('+1 day');
                    break;
                case 'week':
                    $start = $current->setTime(0, 0);
                    $end = min($start->modify('+6 days')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('d.m').'-'.$end->format('d.m');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'quarter':
                    $startMonth = (int) $current->format('n');
                    $startMonth = (int) floor(($startMonth - 1) / 3) * 3 + 1;
                    $start = new \DateTimeImmutable($current->format('Y').'-'.sprintf('%02d', $startMonth).'-01 00:00:00');
                    $end = min($start->modify('+3 months -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = 'Q'.(((int) (($startMonth - 1) / 3)) + 1).' '.$start->format('Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'year':
                    $start = new \DateTimeImmutable($current->format('Y-01-01 00:00:00'));
                    $end = min($start->modify('+1 year -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'month':
                default:
                    $start = new \DateTimeImmutable($current->format('Y-m-01 00:00:00'));
                    $end = min($start->modify('+1 month -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('m.Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
            }
            $periods[] = ['label' => $label, 'start' => $start, 'end' => $end];
        }

        return $periods;
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
