<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashIntragroupDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CashIntragroupDecision> */
final class CashIntragroupDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashIntragroupDecision::class);
    }

    /** @return list<CashIntragroupDecision> */
    public function forGroup(string $companyId): array
    {
        return $this->findBy(['companyId' => $companyId], ['id' => 'ASC']);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashIntragroupDecision
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }
}
