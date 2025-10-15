<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\PaymentPlan;
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
    public function sumByDay(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): array
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
            ->groupBy('plannedDate')
            ->orderBy('plannedDate', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to), Types::DATE_IMMUTABLE);

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
}
