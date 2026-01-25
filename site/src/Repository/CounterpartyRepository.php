<?php

namespace App\Repository;

use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counterparty>
 */
class CounterpartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counterparty::class);
    }

    /**
     * @return Counterparty[]
     */
    public function findByFilters(Company $company, ?CounterpartyType $type, ?string $q, bool $showArchived, array $sort = ['name' => 'ASC']): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company);

        if ($type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }

        if ($q) {
            $qb->andWhere('LOWER(c.name) LIKE :q OR c.inn LIKE :q')
               ->setParameter('q', '%'.strtolower($q).'%');
        }

        if (!$showArchived) {
            $qb->andWhere('c.isArchived = false');
        }

        foreach ($sort as $field => $direction) {
            $qb->addOrderBy('c.'.$field, $direction);
        }

        return $qb->getQuery()->getResult();
    }
}
