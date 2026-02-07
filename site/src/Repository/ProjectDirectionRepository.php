<?php

namespace App\Repository;

use App\Company\Entity\Company;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectDirectionRepository extends ServiceEntityRepository
{
    private const DEFAULT_PROJECT_NAME = 'Основной';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectDirection::class);
    }

    public function findDefaultForCompany(Company $company): ?ProjectDirection
    {
        return $this->findOneBy([
            'company' => $company,
            'name' => self::DEFAULT_PROJECT_NAME,
        ]);
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
        $allDirections = $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();

        $childrenMap = [];
        $roots = [];
        foreach ($allDirections as $direction) {
            if (null === $direction->getParent()) {
                $roots[] = $direction;
            } else {
                $childrenMap[$direction->getParent()->getId()][] = $direction;
            }
        }

        usort(
            $roots,
            static fn (ProjectDirection $a, ProjectDirection $b) => [$a->getSort(), $a->getName()] <=> [$b->getSort(), $b->getName()]
        );

        foreach ($childrenMap as &$children) {
            usort(
                $children,
                static fn (ProjectDirection $a, ProjectDirection $b) => $a->getSort() <=> $b->getSort()
            );
        }
        unset($children);

        $result = [];
        foreach ($roots as $root) {
            $this->collectTree($root, $result, $childrenMap);
        }

        return $result;
    }

    private function collectTree(ProjectDirection $node, array &$result, array $childrenMap): void
    {
        $result[] = $node;
        $nodeId = $node->getId();
        if (isset($childrenMap[$nodeId])) {
            foreach ($childrenMap[$nodeId] as $child) {
                $this->collectTree($child, $result, $childrenMap);
            }
        }
    }

    /**
     * @return ProjectDirection[]
     */
    public function collectSelfAndDescendants(ProjectDirection $root): array
    {
        $result = [$root];

        foreach ($root->getChildren() as $child) {
            $result = array_merge($result, $this->collectSelfAndDescendants($child));
        }

        return $result;
    }
}
