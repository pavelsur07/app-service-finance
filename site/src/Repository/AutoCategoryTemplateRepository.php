<?php

namespace App\Repository;

use App\Entity\AutoCategoryTemplate;
use App\Entity\Company;
use App\Enum\AutoTemplateDirection;
use App\Enum\AutoTemplateScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutoCategoryTemplate>
 */
class AutoCategoryTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutoCategoryTemplate::class);
    }

    /**
     * @return list<AutoCategoryTemplate>
     */
    public function findActiveForCompanyAndScope(Company $company, AutoTemplateScope $scope): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.scope = :scope')
            ->andWhere('t.isActive = true')
            ->setParameter('company', $company)
            ->setParameter('scope', $scope)
            ->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AutoCategoryTemplate>
     */
    public function findActiveForCashflowByDirection(Company $company, AutoTemplateDirection $directionOrAny): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.scope = :scope')
            ->andWhere('t.isActive = true')
            ->setParameter('company', $company)
            ->setParameter('scope', AutoTemplateScope::CASHFLOW);

        if ($directionOrAny !== AutoTemplateDirection::ANY) {
            $qb->andWhere('t.direction IN (:dirs)')
                ->setParameter('dirs', [AutoTemplateDirection::ANY, $directionOrAny]);
        }

        $qb->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
