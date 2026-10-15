<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashCategoryAmount;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Webmozart\Assert\Assert;

/**
 * Агрегаты ДДС для дашборда Analytics. Читается только через `CashFacade`.
 *
 * Запросы перенесены из `CashTransactionRepository` и `MoneyFundMovementRepository`
 * без изменения условий (этап 7.1 плана Cash): переводы исключены, период — по дню
 * включительно, валюта операции совпадает с запрошенной.
 */
final readonly class CashDashboardQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function sumInflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $result = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->select('COALESCE(SUM(t.amount), 0) as inflow')
            ->join('t.moneyAccount', 'ma')
            ->andWhere('t.direction = :inflow')
            ->andWhere('t.currency = ma.currency')
            ->setParameter('inflow', CashDirection::INFLOW)
            ->getQuery()
            ->getSingleScalarResult();

        return bcadd((string) $result, '0', 2);
    }

    /**
     * @return list<CashDailyAmount>
     */
    public function sumInflowByDayExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $qb = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->join('t.moneyAccount', 'ma')
            ->andWhere('t.direction = :inflow')
            ->andWhere('t.currency = ma.currency')
            ->setParameter('inflow', CashDirection::INFLOW);

        return $this->dailySeries($qb);
    }

    /**
     * Сумма расходов со знаком, как она хранится; модуль берёт потребитель.
     */
    public function sumOutflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $result = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->select('COALESCE(SUM(t.amount), 0) as outflow')
            ->andWhere('t.direction = :outflow')
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->getQuery()
            ->getSingleScalarResult();

        return bcadd((string) $result, '0', 2);
    }

    /**
     * @return list<CashDailyAmount>
     */
    public function sumOutflowByDayExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $qb = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->andWhere('t.direction = :outflow')
            ->setParameter('outflow', CashDirection::OUTFLOW);

        return $this->dailySeries($qb);
    }

    /**
     * Сумма разбивок по системной статье CAPEX со знаком, как она хранится.
     */
    public function sumCapexOutflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $result = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->select('COALESCE(SUM(split.amount), 0) as outflow')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
            ->andWhere('t.direction = :outflow')
            ->andWhere('category.systemCode = :systemCode')
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('systemCode', 'CAPEX')
            ->getQuery()
            ->getSingleScalarResult();

        return bcadd((string) $result, '0', 2);
    }

    /**
     * @return list<CashCategoryAmount> по убыванию суммы
     */
    public function sumOutflowByCategoryExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        /** @var list<array{categoryId: string|null, categoryName: string|null, sumAbs: string|null}> $rows */
        $rows = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            // COALESCE по сумме обязателен: у транзакции без категории строк нет, и без него
            // корзина «без категории» показывала бы ноль вместо реального оборота.
            ->select('IDENTITY(split.cashflowCategory) as categoryId', 'COALESCE(category.name, :uncategorized) as categoryName', 'ABS(COALESCE(SUM(COALESCE(split.amount, t.amount)), 0)) as sumAbs')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
            ->andWhere('t.direction = :outflow')
            ->groupBy('split.cashflowCategory', 'category.name')
            ->orderBy('sumAbs', \SortDirection::Descending)
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('uncategorized', 'Без категории')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): CashCategoryAmount => new CashCategoryAmount(
            null !== $row['categoryId'] ? (string) $row['categoryId'] : null,
            (string) $row['categoryName'],
            bcadd((string) ($row['sumAbs'] ?? '0'), '0', 2),
        ), $rows);
    }

    /**
     * Сумма разбивок по видам потока; виды без операций — '0.00'.
     *
     * @return array{OPERATING: string, INVESTING: string, FINANCING: string}
     */
    public function sumNetByFlowKindExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $rows = $this->transactionsInPeriod($companyId, $from, $to, $currency)
            ->select('category.flowKind as flowKind', 'COALESCE(SUM(split.amount), 0) as net')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
            ->andWhere('category.flowKind IS NOT NULL')
            ->groupBy('category.flowKind')
            ->getQuery()
            ->getArrayResult();

        $result = [
            CashflowFlowKind::OPERATING->value => '0.00',
            CashflowFlowKind::INVESTING->value => '0.00',
            CashflowFlowKind::FINANCING->value => '0.00',
        ];

        foreach ($rows as $row) {
            $flowKindValue = $row['flowKind'] ?? null;
            $flowKind = $flowKindValue instanceof CashflowFlowKind
                ? $flowKindValue->value
                : (string) $flowKindValue;

            if (!array_key_exists($flowKind, $result)) {
                continue;
            }

            $result[$flowKind] = bcadd((string) ($row['net'] ?? '0'), '0', 2);
        }

        return $result;
    }

    /**
     * Остаток всех фондов компании в валюте на конец дня, в минорных единицах.
     */
    public function sumFundBalancesMinorUpToDate(string $companyId, \DateTimeImmutable $date, string $currency): int
    {
        Assert::uuid($companyId);

        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.fund) AS fundId', 'COALESCE(SUM(m.amountMinor), 0) AS amountMinor')
            ->from(MoneyFundMovement::class, 'm')
            ->join('m.fund', 'fund')
            ->andWhere('m.company = :company')
            ->andWhere('fund.currency = :currency')
            ->andWhere('m.occurredAt <= :date')
            ->setParameter('company', $companyId)
            ->setParameter('currency', $currency)
            ->setParameter('date', $date->setTime(23, 59, 59))
            ->groupBy('m.fund')
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['amountMinor'];
        }

        return $total;
    }

    private function transactionsInPeriod(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): QueryBuilder
    {
        Assert::uuid($companyId);

        return $this->em->createQueryBuilder()
            ->from(CashTransaction::class, 't')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.currency = :currency')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $companyId)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('isTransfer', false)
            ->setParameter('currency', $currency);
    }

    /**
     * @return list<CashDailyAmount>
     */
    private function dailySeries(QueryBuilder $qb): array
    {
        $rows = $qb
            ->select('t.occurredAt as date', 'COALESCE(SUM(t.amount), 0) as value')
            ->groupBy('date')
            ->orderBy('date', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        $series = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }

            $series[] = new CashDailyAmount((string) $date, bcadd((string) ($row['value'] ?? '0'), '0', 2));
        }

        return $series;
    }
}
