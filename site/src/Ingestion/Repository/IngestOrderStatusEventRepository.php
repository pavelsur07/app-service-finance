<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrderStatusEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestOrderStatusEvent>
 */
final class IngestOrderStatusEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestOrderStatusEvent::class);
    }

    /**
     * @return list<IngestOrderStatusEvent>
     */
    public function findByOrder(string $companyId, string $orderId): array
    {
        /** @var list<IngestOrderStatusEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.orderId = :orderId')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderId', $orderId)
            ->orderBy('e.observedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $events;
    }

    public function countByOrder(string $companyId, string $orderId): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.orderId = :orderId')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Последний использованный порядковый номер наблюдения на заказ в пределах
     * одного сырья.
     *
     * Подстраховка на случай повторного разбора не завершившейся записи: сама
     * нормализация пишет всё одной транзакцией, поэтому половины событий
     * остаться не может, а успешно разобранное сырьё идёт по пути повтора и
     * событий не создаёт вовсе.
     *
     * @return array<string, int> orderId => максимальный номер
     */
    public function lastOccurrencesForRawRecord(string $companyId, string $rawRecordId): array
    {
        /** @var list<array{orderId: string, maxOccurrence: int|string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.orderId AS orderId', 'MAX(e.occurrence) AS maxOccurrence')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.rawRecordId = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->groupBy('e.orderId')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['orderId']] = (int) $row['maxOccurrence'];
        }

        return $indexed;
    }

    public function save(IngestOrderStatusEvent $event): void
    {
        $this->getEntityManager()->persist($event);
    }
}
