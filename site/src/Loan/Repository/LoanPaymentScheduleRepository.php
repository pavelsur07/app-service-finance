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
}
