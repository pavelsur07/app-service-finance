<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\OzonReconciliationLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<OzonReconciliationLine>
 */
final class OzonReconciliationLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonReconciliationLine::class);
    }

    public function save(OzonReconciliationLine $line): void
    {
        $this->getEntityManager()->persist($line);
    }

    /**
     * @return list<OzonReconciliationLine>
     */
    public function findByRun(string $companyId, string $runId): array
    {
        Assert::uuid($companyId);
        Assert::uuid($runId);

        /** @var list<OzonReconciliationLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->where('l.companyId = :companyId')
            ->andWhere('l.runId = :runId')
            ->setParameter('companyId', $companyId)
            ->setParameter('runId', $runId)
            ->orderBy('l.checkType', 'ASC')
            ->addOrderBy('l.block', 'ASC')
            ->addOrderBy('l.categoryCode', 'ASC')
            ->getQuery()
            ->getResult();

        return $lines;
    }

    /**
     * Сносит строки снимка перед пересозданием. DQL-удаление, без flush: транзакцией управляет Action.
     */
    public function deleteByRun(string $companyId, string $runId): int
    {
        Assert::uuid($companyId);
        Assert::uuid($runId);

        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.companyId = :companyId')
            ->andWhere('l.runId = :runId')
            ->setParameter('companyId', $companyId)
            ->setParameter('runId', $runId)
            ->getQuery()
            ->execute();
    }
}
