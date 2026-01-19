<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectDirectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectDirection::class);
    }

    /**
     * @return ProjectDirection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->findTreeByCompany($company);
    }

    /**
     * @return ProjectDirection[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->andWhere('d.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('d.sort', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ProjectDirection[]
     */
    public function findTreeByCompany(Company $company): array
    {
        $roots = $this->findRootByCompany($company);
        $result = [];
        foreach ($roots as $root) {
            $this->collectTree($root, $result);
        }

        return $result;
    }

    private function collectTree(ProjectDirection $node, array &$result): void
    {
        $result[] = $node;
        foreach ($node->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }
}
