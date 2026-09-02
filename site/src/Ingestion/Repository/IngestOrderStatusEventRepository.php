<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Enum\IngestOrderStatus;
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
        /** @var list<array{orderId: string, rawStatus: string, previousStatus: ?IngestOrderStatus}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.orderId AS orderId', 'e.rawStatus AS rawStatus', 'e.previousStatus AS previousStatus')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.rawRecordId = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): string => self::observationKey(
                $row['orderId'],
                $row['rawStatus'],
                $row['previousStatus'],
            ),
            $rows,
        );
    }

    /**
     * Ключ дедупликации наблюдения — тот же набор колонок, что и в уникальном
     * индексе. Два определения этого ключа неминуемо разошлись бы, и
     * расхождение проявилось бы падением flush на проде.
     */
    public static function observationKey(string $orderId, string $rawStatus, ?IngestOrderStatus $previousStatus): string
    {
        return $orderId."\0".$rawStatus."\0".(null === $previousStatus ? '' : $previousStatus->value);
    }

    public function save(IngestOrderStatusEvent $event): void
    {
        $this->getEntityManager()->persist($event);
    }
}
