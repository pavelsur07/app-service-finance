<?php

declare(strict_types=1);

namespace App\Finance\Facts;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PLDailyTotalFactsProvider implements FactsProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLCategoryRepository $plCategories,
        private readonly ProjectDirectionRepository $projectDirections,
    ) {
    }

    /**
     * Возвращает сумму за период по коду категории:
     * SUM(amountIncome) - SUM(amountExpense) из PLDailyTotal по company + category + date ∈ [from; to]
     *
     * @param ProjectDirection|list<ProjectDirection>|null $projectDirection
     * @param string|list<string>|null $responsibilityCenterId
     */
    public function value(
        Company $company,
        PlReportPeriod $period,
        string $code,
        ProjectDirection|array|null $projectDirection = null,
        string|array|null $responsibilityCenterId = null,
    ): float {
        $code = trim((string) $code);
        if ('' === $code) {
            return 0.0;
        }

        if ([] === $projectDirection || [] === $responsibilityCenterId) {
            return 0.0;
        }

        /** @var PLCategory|null $cat */
        $cat = $this->plCategories->findOneBy([
            'company' => $company,
            'code' => $code,
        ]);
        if (!$cat) {
            return 0.0;
        }

        $from = $period->from;
        $to = $period->to;

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

        if (null !== $projectDirection) {
            $selectedProjects = $projectDirection instanceof ProjectDirection ? [$projectDirection] : $projectDirection;
            $nodesById = [];
            foreach ($selectedProjects as $selectedProject) {
                foreach ($this->projectDirections->collectSelfAndDescendants($selectedProject) as $node) {
                    $nodesById[(string) $node->getId()] = $node;
                }
            }

            $qb
                ->andWhere('dt.projectDirection IN (:pds)')
                ->setParameter('pds', array_values($nodesById));
        }

        if (\is_array($responsibilityCenterId)) {
            $responsibilityCenterIds = array_values(array_unique(array_filter(
                $responsibilityCenterId,
                static fn (mixed $id): bool => \is_string($id) && '' !== $id,
            )));
            if ([] === $responsibilityCenterIds) {
                return 0.0;
            }

            $qb
                ->andWhere('dt.responsibilityCenterId IN (:responsibilityCenterIds)')
                ->setParameter('responsibilityCenterIds', $responsibilityCenterIds);
        } elseif (null !== $responsibilityCenterId && '' !== $responsibilityCenterId) {
            $qb
                ->andWhere('dt.responsibilityCenterId = :responsibilityCenterId')
                ->setParameter('responsibilityCenterId', $responsibilityCenterId);
        }

        $row = $qb->getQuery()->getOneOrNullResult();
        $income = isset($row['sIncome']) ? (float) $row['sIncome'] : 0.0;
        $expense = isset($row['sExpense']) ? (float) $row['sExpense'] : 0.0;

        return $income - $expense;
    }
}
