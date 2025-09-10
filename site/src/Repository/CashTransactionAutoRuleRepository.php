<?php

namespace App\Repository;

use App\Entity\CashTransactionAutoRule;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashTransactionAutoRule>
 */
class CashTransactionAutoRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransactionAutoRule::class);
    }

    /**
     * @return CashTransactionAutoRule[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->findBy(['company' => $company], ['name' => 'ASC']);
    }
}
