<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestOrderItem>
 */
final class IngestOrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestOrderItem::class);
    }

    /**
     * @return array<string, IngestOrderItem> lineKey => позиция
     */
    public function findByOrderIndexedByLineKey(string $companyId, string $orderId): array
    {
        /** @var list<IngestOrderItem> $items */
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.companyId = :companyId')
            ->andWhere('i.orderId = :orderId')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->getLineKey()] = $item;
        }

        return $indexed;
    }

    /**
     * Позиции сразу для НЕСКОЛЬКИХ заказов.
     *
     * Одна страница коннектора несёт до 20 000 строк, и запрос на заказ дал бы
     * столько же обращений на один batch нормализации.
     *
     * @param list<string> $orderIds
     *
     * @return array<string, array<string, IngestOrderItem>> orderId => lineKey => позиция
     */
    public function findByOrdersIndexedByLineKey(string $companyId, array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        /** @var list<IngestOrderItem> $items */
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.companyId = :companyId')
            ->andWhere('i.orderId IN (:orderIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderIds', $orderIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->getOrderId()][$item->getLineKey()] = $item;
        }

        return $indexed;
    }

    /**
     * Позиции без связи с листингом — видимая очередь на разбор.
     *
     * @return list<IngestOrderItem>
     */
    public function findUnlinked(string $companyId, int $limit): array
    {
        /** @var list<IngestOrderItem> $items */
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.companyId = :companyId')
            ->andWhere('i.listingId IS NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function save(IngestOrderItem $item): void
    {
        $this->getEntityManager()->persist($item);
    }
}
