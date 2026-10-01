<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\OzonReconciliationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<OzonReconciliationRun>
 */
final class OzonReconciliationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonReconciliationRun::class);
    }

    public function save(OzonReconciliationRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }

    public function findByPeriod(string $companyId, \DateTimeImmutable $periodFrom, \DateTimeImmutable $periodTo): ?OzonReconciliationRun
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.periodFrom = :periodFrom')
            ->andWhere('r.periodTo = :periodTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('periodFrom', $periodFrom, 'date_immutable')
            ->setParameter('periodTo', $periodTo, 'date_immutable')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIdForCompany(string $companyId, string $runId): ?OzonReconciliationRun
    {
        Assert::uuid($companyId);
        Assert::uuid($runId);

        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.id = :id')
            ->setParameter('companyId', $companyId)
            ->setParameter('id', $runId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<OzonReconciliationRun> свежие периоды сверху
     */
    public function findRecentByCompany(string $companyId, int $limit = 24): array
    {
        Assert::uuid($companyId);
        Assert::range($limit, 1, 200);

        /** @var list<OzonReconciliationRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('r.periodFrom', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }
}
