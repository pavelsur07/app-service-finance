<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceOzonRealization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceOzonRealizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceOzonRealization::class);
    }

    /**
     * Проверить — загружена ли реализация за период.
     */
    public function existsForPeriod(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): bool {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.companyId = :companyId')
            ->andWhere('r.periodFrom = :periodFrom')
            ->andWhere('r.periodTo = :periodTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('periodFrom', new \DateTimeImmutable($periodFrom))
            ->setParameter('periodTo', new \DateTimeImmutable($periodTo))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Пометить строки реализации за период как обработанные.
     *
     * @return int количество обновлённых записей
     */
    public function markProcessed(
        string $companyId,
        string $plDocumentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.plDocumentId', ':plDocumentId')
            ->where('r.companyId = :companyId')
            ->andWhere('r.periodFrom = :periodFrom')
            ->andWhere('r.periodTo = :periodTo')
            ->andWhere('r.plDocumentId IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('plDocumentId', $plDocumentId)
            ->setParameter('periodFrom', new \DateTimeImmutable($periodFrom))
            ->setParameter('periodTo', new \DateTimeImmutable($periodTo))
            ->getQuery()
            ->execute();
    }

    public function save(MarketplaceOzonRealization $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }
}
