<?php
declare(strict_types=1);

namespace App\Finance\Facts;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Entity\PLDailyTotal;
use App\Repository\PLCategoryRepository;
use App\Finance\Report\PlReportPeriod;
use Doctrine\ORM\EntityManagerInterface;

final class PLDailyTotalFactsProvider implements FactsProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLCategoryRepository $plCategories,
    ) {}

    /**
     * Возвращает сумму за период по коду категории:
     * SUM(amountIncome) - SUM(amountExpense) из PLDailyTotal по company + category + date ∈ [from; to]
     */
    public function value(Company $company, PlReportPeriod $period, string $code): float
    {
        $code = trim((string) $code);
        if ($code === '') {
            return 0.0;
        }

        /** @var PLCategory|null $cat */
        $cat = $this->plCategories->findOneBy([
            'company' => $company,
            'code'    => $code,
        ]);
        if (!$cat) {
            return 0.0;
        }

        $from = $period->from;
        $to   = $period->to;

        $qb = $this->em->createQueryBuilder();
        $qb
            ->select('COALESCE(SUM(dt.amountIncome), 0) as sIncome, COALESCE(SUM(dt.amountExpense), 0) as sExpense')
            ->from(PLDailyTotal::class, 'dt')
            ->andWhere('dt.company = :company')
            ->andWhere('dt.plCategory = :cat')
            ->andWhere('dt.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('cat', $cat)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $row = $qb->getQuery()->getOneOrNullResult();
        $income  = isset($row['sIncome']) ? (float) $row['sIncome'] : 0.0;
        $expense = isset($row['sExpense']) ? (float) $row['sExpense'] : 0.0;

        return $income - $expense;
    }
}
