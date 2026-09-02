<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestOrder>
 */
final class IngestOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestOrder::class);
    }

    /**
     * connectionRef обязателен: posting_number уникален в пределах кабинета
     * продавца, а не глобально. Без него два кабинета одной компании слились
     * бы в одну запись заказа.
     */
    public function findByExternalId(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        string $externalId,
    ): ?IngestOrder {
        return $this->createQueryBuilder('o')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.source = :source')
            ->andWhere('o.connectionRef = :connectionRef')
            ->andWhere('o.externalId = :externalId')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, IngestOrder> externalId => заказ
     */
    public function findManyByExternalIdsIndexed(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        array $externalIds,
    ): array {
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<IngestOrder> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.source = :source')
            ->andWhere('o.connectionRef = :connectionRef')
            ->andWhere('o.externalId IN (:externalIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('externalIds', $externalIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($orders as $order) {
            $indexed[$order->getExternalId()] = $order;
        }

        return $indexed;
    }

    /**
     * Заказы, которые ещё имеет смысл перепрашивать.
     *
     * connectionRef обязателен: заказы разных кабинетов одной компании
     * опрашиваются разными ключами, и спросить Ozon о чужом отправлении
     * значило бы получить 404 на живом заказе.
     *
     * Терминальность спрашивается у enum'а, а не выражается списком статусов
     * здесь: иначе определение «терминального» разошлось бы между выборкой,
     * монитором и апсертом.
     *
     * @return list<IngestOrder>
     */
    public function findNonTerminalForRefresh(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        \DateTimeImmutable $orderedAfter,
        int $limit,
    ): array {
        $nonTerminal = array_values(array_map(
            static fn (IngestOrderStatus $s): string => $s->value,
            array_filter(
                IngestOrderStatus::cases(),
                static fn (IngestOrderStatus $s): bool => !$s->isTerminal(),
            ),
        ));

        /** @var list<IngestOrder> $orders */
        $orders = $this->createQueryBuilder('o')
            // Заказы БЕЗ статуса идут первыми: им статус нужнее всего, а
            // PostgreSQL при ASC ставит NULL в конец и они опрашивались бы
            // последними — то есть при исчерпании лимита никогда.
            ->addSelect('CASE WHEN o.statusObservedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN observedRank')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.source = :source')
            ->andWhere('o.connectionRef = :connectionRef')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.orderedAt >= :orderedAfter')
            ->andWhere('o.refreshStoppedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('statuses', $nonTerminal)
            ->setParameter('orderedAfter', $orderedAfter)
            ->orderBy('observedRank', 'ASC')
            ->addOrderBy('o.statusObservedAt', 'ASC')
            // Устойчивый третий ключ: без него заказы с одинаковым временем
            // наблюдения (а у не опрошенных оно одинаково всегда — NULL)
            // возвращались бы в произвольном порядке, и при исчерпании лимита
            // часть из них не опрашивалась бы никогда.
            ->addOrderBy('o.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * Заказы, застрявшие в нетерминальном статусе дольше окна опроса.
     *
     * @return list<IngestOrder>
     */
    public function findStuck(string $companyId, \DateTimeImmutable $orderedBefore, int $limit): array
    {
        $nonTerminal = array_values(array_map(
            static fn (IngestOrderStatus $s): string => $s->value,
            array_filter(
                IngestOrderStatus::cases(),
                static fn (IngestOrderStatus $s): bool => !$s->isTerminal(),
            ),
        ));

        /** @var list<IngestOrder> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.orderedAt < :orderedBefore')
            ->andWhere('o.refreshStoppedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', $nonTerminal)
            ->setParameter('orderedBefore', $orderedBefore)
            ->orderBy('o.orderedAt', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $orders;
    }

    public function save(IngestOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }
}
