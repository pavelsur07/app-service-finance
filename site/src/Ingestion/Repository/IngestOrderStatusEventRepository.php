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
     * История переходов заказа в порядке наблюдения.
     *
     * Одной отметки времени мало: все события одного сырья наблюдались
     * одновременно, поэтому последовательность A → B → A → B вернулась бы в
     * произвольном порядке — ровно то, ради чего заведён порядковый номер.
     * Сырьё группируется, внутри него порядок задаёт номер, а `id` замыкает
     * сравнение, чтобы результат был воспроизводим.
     *
     * Лимит обязателен: журнал append-only и растёт, а списочная выборка без
     * границы рано или поздно упирается в память. Возвращаются ПОСЛЕДНИЕ
     * события в хронологическом порядке: лимит по возрастанию отдавал бы
     * вечно одни и те же самые старые переходы, и текущее состояние заказа в
     * доступной истории не появилось бы вовсе.
     *
     * @return list<IngestOrderStatusEvent>
     */
    public function findByOrder(string $companyId, string $orderId, int $limit = 200): array
    {
        // Отбираются ПОСЛЕДНИЕ события, а порядок разворачивается уже здесь.
        //
        // Лимит на выборку по возрастанию отдавал бы вечно одни и те же самые
        // старые переходы: после двухсотого читатель никогда не увидел бы ни
        // одного нового, то есть ни текущего состояния заказа, ни того, как он
        // до него дошёл.
        /** @var list<IngestOrderStatusEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->andWhere('e.companyId = :companyId')
            ->andWhere('e.orderId = :orderId')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderId', $orderId)
            ->orderBy('e.observedAt', 'DESC')
            ->addOrderBy('e.rawRecordId', 'DESC')
            ->addOrderBy('e.occurrence', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return array_reverse($events);
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
