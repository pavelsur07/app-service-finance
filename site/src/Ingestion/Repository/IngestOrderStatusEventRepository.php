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
     * Ключи наблюдений, уже записанных ИЗ ЭТОГО сырья.
     *
     * Одним запросом на весь batch: проверка на каждое событие давала бы до
     * 20 000 COUNT'ов на одну страницу коннектора и всё равно не видела бы
     * события, созданные в этом же прогоне, — Doctrine-запрос не видит
     * непрофлашенные сущности UnitOfWork.
     *
     * @return list<string> «orderId\0rawStatus»
     */
    public function observationKeysForRawRecord(string $companyId, string $rawRecordId): array
    {
        /** @var list<array{orderId: string, rawStatus: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.orderId AS orderId', 'e.rawStatus AS rawStatus')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.rawRecordId = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): string => $row['orderId']."\0".$row['rawStatus'],
            $rows,
        );
    }

    public function save(IngestOrderStatusEvent $event): void
    {
        $this->getEntityManager()->persist($event);
    }
}
