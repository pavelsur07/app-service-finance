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
     * Уже зафиксировано ли ЭТО наблюдение.
     *
     * Ключ — сырьё плюс сырой статус, а не «текущий статус заказа». Повторная
     * нормализация старого raw снова видит расхождение с текущим статусом
     * (устаревшее наблюдение его не двигает) и без этой проверки дописывала бы
     * копию события при каждом прогоне.
     */
    public function existsObservation(
        string $companyId,
        string $orderId,
        ?string $rawRecordId,
        string $rawStatus,
    ): bool {
        if (null === $rawRecordId) {
            return false;
        }

        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.orderId = :orderId')
            ->andWhere('e.rawRecordId = :rawRecordId')
            ->andWhere('e.rawStatus = :rawStatus')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderId', $orderId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->setParameter('rawStatus', $rawStatus);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(IngestOrderStatusEvent $event): void
    {
        $this->getEntityManager()->persist($event);
    }
}
