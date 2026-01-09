<?php

declare(strict_types=1);

namespace App\Loan\Repository;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoanPaymentSchedule>
 */
class LoanPaymentScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoanPaymentSchedule::class);
    }

    /**
     * @return list<LoanPaymentSchedule>
     */
    public function findByLoanOrderedByDueDate(Loan $loan): array
    {
        /** @var list<LoanPaymentSchedule> $result */
        $result = $this->createQueryBuilder('schedule')
            ->where('schedule.loan = :loan')
            ->orderBy('schedule.dueDate', 'ASC')
            ->addOrderBy('schedule.createdAt', 'ASC')
            ->setParameter('loan', $loan)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<LoanPaymentSchedule>
     */
    public function findUnpaidByLoan(Loan $loan): array
    {
        /** @var list<LoanPaymentSchedule> $result */
        $result = $this->createQueryBuilder('schedule')
            ->where('schedule.loan = :loan')
            ->andWhere('schedule.isPaid = :isPaid')
            ->orderBy('schedule.dueDate', 'ASC')
            ->addOrderBy('schedule.createdAt', 'ASC')
            ->setParameter('loan', $loan)
            ->setParameter('isPaid', false, Types::BOOLEAN)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findNextPaymentForLoan(Loan $loan, \DateTimeImmutable $fromDate): ?LoanPaymentSchedule
    {
        /** @var LoanPaymentSchedule|null $result */
        $result = $this->createQueryBuilder('schedule')
            ->where('schedule.loan = :loan')
            ->andWhere('schedule.dueDate >= :fromDate')
            ->orderBy('schedule.dueDate', 'ASC')
            ->addOrderBy('schedule.createdAt', 'ASC')
            ->setParameter('loan', $loan)
            ->setParameter('fromDate', $fromDate, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function sumInterestForLoanAndPeriod(Loan $loan, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('schedule')
            ->select('COALESCE(SUM(schedule.interestPart), 0) as totalInterest')
            ->where('schedule.loan = :loan')
            ->andWhere('schedule.dueDate BETWEEN :from AND :to')
            ->setParameter('loan', $loan)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $result;
    }
}
