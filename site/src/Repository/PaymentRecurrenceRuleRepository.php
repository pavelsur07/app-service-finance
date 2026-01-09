<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\PaymentRecurrenceRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PaymentRecurrenceRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRecurrenceRule::class);
    }

    /**
     * @return list<PaymentRecurrenceRule>
     */
    public function findActiveByCompany(Company $company): array
    {
        $query = $this->createQueryBuilder('rule')
            ->where('rule.company = :company')
            ->andWhere('rule.active = true')
            ->setParameter('company', $company)
            ->orderBy('rule.id', 'ASC')
            ->getQuery();

        /** @var list<PaymentRecurrenceRule> $result */
        $result = $query->getResult();

        return $result;
    }
}
