<?php

namespace App\Report\Cashflow;

use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Enum\CashDirection;
use App\Repository\CashTransactionRepository;

final class CashflowReportBuilder
{
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

        $rows = $this->transactionRepository->createQueryBuilder('t')
            ->select('IDENTITY(t.cashflowCategory) AS category', 't.direction', 't.amount', 't.currency', 't.occurredAt')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->getQuery()->getArrayResult();

        $companyTotals = [];
        foreach ($rows as $row) {
            $catId = $row['category'];
            if (!$catId || !isset($categoryMap[$catId])) {
                continue;
            }

            $amount = (float) $row['amount'];
            $direction = $row['direction'] instanceof CashDirection
                ? $row['direction']->value
                : $row['direction'];
            $amount = $direction === CashDirection::OUTFLOW->value
                ? -abs($amount)
                : abs($amount);
            $currency = $row['currency'];
            $periodIndex = $this->findPeriodIndex($periods, $row['occurredAt']);
            if (null === $periodIndex) {
                continue;
            }

            if (!isset($categoryMap[$catId]['totals'][$currency])) {
                $categoryMap[$catId]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
            }

            $categoryMap[$catId]['totals'][$currency][$periodIndex] += $amount;
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
            'date_from' => $from,
            'date_to' => $to,
            'periods' => $periods,
            'categories' => $rootCategories,
            'categoryTotals' => $categoryMap,
            'openings' => $openings,
            'closings' => $closings,
            'tree' => $tree,
            'categoryTree' => $categoryTree,
        ];
    }

    /**
     * @param \App\Entity\CashflowCategory[] $categories // полный список, как вернул findTreeByCompany()
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
     * @param \App\Entity\CashflowCategory[] $allCategories // полный список (findTreeByCompany)
     * @param array<string,array{entity:\App\Entity\CashflowCategory, totals:array<string,array<int,float>>}> $categoryMap
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
                    $start = $current;
                    $end = $current;
                    $label = $current->format('d.m.Y');
                    $current = $current->modify('+1 day');
                    break;
                case 'week':
                    $start = $current;
                    $end = min($start->modify('+6 days'), $to);
                    $label = $start->format('d.m').'-'.$end->format('d.m');
                    $current = $end->modify('+1 day');
                    break;
                case 'quarter':
                    $startMonth = (int) $current->format('n');
                    $startMonth = (int) floor(($startMonth - 1) / 3) * 3 + 1;
                    $start = new \DateTimeImmutable($current->format('Y').'-'.sprintf('%02d', $startMonth).'-01');
                    $end = min($start->modify('+3 months -1 day'), $to);
                    $label = 'Q'.(((int) (($startMonth - 1) / 3)) + 1).' '.$start->format('Y');
                    $current = $end->modify('+1 day');
                    break;
                case 'year':
                    $start = new \DateTimeImmutable($current->format('Y-01-01'));
                    $end = min($start->modify('+1 year -1 day'), $to);
                    $label = $start->format('Y');
                    $current = $end->modify('+1 day');
                    break;
                case 'month':
                default:
                    $start = new \DateTimeImmutable($current->format('Y-m-01'));
                    $end = min($start->modify('+1 month -1 day'), $to);
                    $label = $start->format('m.Y');
                    $current = $end->modify('+1 day');
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
