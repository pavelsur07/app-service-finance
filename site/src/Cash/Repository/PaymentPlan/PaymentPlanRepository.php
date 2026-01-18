<?php

namespace App\Cash\Repository\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Entity\Company;
use App\Entity\PaymentRecurrenceRule;
use App\Enum\PaymentPlanStatus;
use App\Enum\PaymentPlanType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

class PaymentPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentPlan::class);
    }

    /**
     * @return list<PaymentPlan>
     */
    public function findPlannedByCompanyAndPeriod(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $plannedStatuses = [
            PaymentPlanStatus::PLANNED,
            PaymentPlanStatus::APPROVED,
        ];

        $query = $this->createQueryBuilder('plan')
            ->where('plan.company = :company')
            ->andWhere('plan.plannedAt BETWEEN :from AND :to')
            ->andWhere('plan.status IN (:statuses)')
            ->setParameter('company', $company)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to), Types::DATE_IMMUTABLE)
            ->setParameter(
                'statuses',
                array_map(static fn (PaymentPlanStatus $status): string => $status->value, $plannedStatuses),
                ArrayParameterType::STRING
            )
            ->orderBy('plan.plannedAt', 'ASC')
            ->addOrderBy('plan.createdAt', 'ASC')
            ->getQuery();

        /** @var list<PaymentPlan> $result */
        $result = $query->getResult();

        return $result;
    }

    /**
     * @return array<string, array{inflow:string,outflow:string,transfer:string}>
     */
    public function sumByDay(Company $company, \DateTimeInterface $from, \DateTimeInterface $to, ?MoneyAccount $account = null): array
    {
        $qb = $this->createQueryBuilder('plan')
            ->select('plan.plannedAt AS plannedDate')
            ->addSelect(sprintf(
                "SUM(CASE WHEN plan.type = '%s' THEN plan.amount ELSE 0 END) AS inflow",
                PaymentPlanType::INFLOW->value
            ))
            ->addSelect(sprintf(
                "SUM(CASE WHEN plan.type = '%s' THEN plan.amount ELSE 0 END) AS outflow",
                PaymentPlanType::OUTFLOW->value
            ))
            ->addSelect(sprintf(
                "SUM(CASE WHEN plan.type = '%s' THEN plan.amount ELSE 0 END) AS transfer",
                PaymentPlanType::TRANSFER->value
            ))
            ->where('plan.company = :company')
            ->andWhere('plan.plannedAt BETWEEN :from AND :to')
            ->andWhere('plan.status IN (:statuses)')
            ->groupBy('plannedDate')
            ->orderBy('plannedDate', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to), Types::DATE_IMMUTABLE)
            ->setParameter(
                'statuses',
                [
                    PaymentPlanStatus::DRAFT->value,
                    PaymentPlanStatus::PLANNED->value,
                    PaymentPlanStatus::APPROVED->value,
                ],
                ArrayParameterType::STRING
            );

        if (null !== $account) {
            $qb->andWhere('plan.moneyAccount = :account')
                ->setParameter('account', $account);
        }

        $rawResults = $qb->getQuery()->getArrayResult();

        $totals = [];
        foreach ($rawResults as $row) {
            $date = $row['plannedDate'];
            if ($date instanceof \DateTimeInterface) {
                $key = $date->format('Y-m-d');
            } else {
                $key = (string) $date;
            }

            $totals[$key] = [
                'inflow' => (string) $row['inflow'],
                'outflow' => (string) $row['outflow'],
                'transfer' => (string) $row['transfer'],
            ];
        }

        return $totals;
    }

    public function findTemplateForRecurrenceRule(PaymentRecurrenceRule $rule): ?PaymentPlan
    {
        return $this->createQueryBuilder('plan')
            ->where('plan.recurrenceRule = :rule')
            ->orderBy('plan.plannedAt', 'ASC')
            ->setMaxResults(1)
            ->setParameter('rule', $rule)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsRecurrenceDuplicate(Company $company, PaymentRecurrenceRule $rule, \DateTimeInterface $plannedAt, string $amount, CashflowCategory $category): bool
    {
        $result = $this->createQueryBuilder('plan')
            ->select('1')
            ->where('plan.company = :company')
            ->andWhere('plan.recurrenceRule = :rule')
            ->andWhere('plan.plannedAt = :plannedAt')
            ->andWhere('plan.amount = :amount')
            ->andWhere('plan.cashflowCategory = :category')
            ->setMaxResults(1)
            ->setParameter('company', $company)
            ->setParameter('rule', $rule)
            ->setParameter('plannedAt', \DateTimeImmutable::createFromInterface($plannedAt), Types::DATE_IMMUTABLE)
            ->setParameter('amount', $amount)
            ->setParameter('category', $category)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }
}
