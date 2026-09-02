<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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
     * `$requireExternalOrderId` отсекает заказы, которые спросить не у кого.
     * У WB это заказы, известные только из потока изменений statistics:
     * эндпоинта «статус по srid» не существует. Отсев обязан идти ДО лимита —
     * у таких заказов `statusObservedAt` остаётся NULL навсегда, поэтому они
     * вечно первые в очереди и, отсеиваясь уже после выборки, съедали бы лимит
     * целиком, оставляя пригодные заказы неопрошенными.
     *
     * @return list<IngestOrder>
     */
    public function findNonTerminalForRefresh(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        \DateTimeImmutable $orderedAfter,
        int $limit,
        bool $requireExternalOrderId = false,
    ): array {
        $nonTerminal = self::nonTerminalStatuses();

        $qb = $this->createQueryBuilder('o');

        if ($requireExternalOrderId) {
            $qb->andWhere('o.externalOrderId IS NOT NULL');
        }

        /** @var list<IngestOrder> $orders */
        $orders = $qb
            // Очередь планируется по времени ПОПЫТКИ, а не наблюдения.
            //
            // Попытка бывает без наблюдения: 404, ответ без поля статуса,
            // отсутствие заказа в успешном ответе WB. По отметке наблюдения
            // такие заказы стояли бы в начале очереди вечно — сортировка
            // устойчива, — и при исчерпании лимита остальные заказы кабинета
            // не опрашивались бы никогда.
            //
            // Ни разу не опрошенные идут первыми: им статус нужнее всего, а
            // PostgreSQL при ASC ставит NULL в конец.
            ->addSelect('CASE WHEN o.statusRefreshAttemptedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN attemptRank')
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
            ->orderBy('attemptRank', 'ASC')
            ->addOrderBy('o.statusRefreshAttemptedAt', 'ASC')
            // Устойчивый третий ключ: без него заказы с одинаковым временем
            // попытки (а у ни разу не опрошенных оно одинаково всегда — NULL)
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
        $nonTerminal = self::nonTerminalStatuses();

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

    /**
     * Заказы под блокировкой записи, с принудительным перечитыванием из БД.
     *
     * ОДИН запрос на пачку, а не запрос на заказ: перепрос применяет до
     * тысячи наблюдений за прогон, и блокировка по одной строке дала бы
     * тысячу обращений внутри транзакции.
     *
     * `HINT_REFRESH` обязателен: без него Doctrine вернёт экземпляры из карты
     * идентичности с полями, прочитанными до внешних HTTP-запросов, и
     * `SELECT ... FOR UPDATE` защитил бы строки в базе, оставив в памяти
     * устаревшее состояние — то есть ровно ту гонку, ради которой блокировка и
     * берётся.
     *
     * Порядок по `id` задаёт единый порядок захвата блокировок: два прогона,
     * взявшие пересекающиеся пачки в разном порядке, встали бы в взаимную
     * блокировку.
     *
     * @param list<string> $orderIds
     *
     * @return array<string, IngestOrder> ключ — идентификатор заказа
     */
    public function findManyForUpdate(string $companyId, array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        $query = $this->createQueryBuilder('o')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.id IN (:orderIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('orderIds', array_values(array_unique($orderIds)))
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true);

        /** @var list<IngestOrder> $orders */
        $orders = $query->getResult();

        $indexed = [];
        foreach ($orders as $order) {
            $indexed[$order->getId()] = $order;
        }

        return $indexed;
    }

    /**
     * Застрявшие заказы ВСЕХ компаний — для часового прогона без фильтра.
     *
     * @companyScopeExempt Зависание заказа не зависит от того, живо ли ещё
     * подключение, которым его загрузили. Отключённый кабинет оставлял бы свои
     * заказы вечно нетерминальными и невидимыми: опрашивать их уже некому, а
     * пометить «завис» было бы некому тоже. Cron-прогон обязан пройти по всем
     * компаниям; вызов с `--company-id` пользуется методом выше.
     *
     * @return list<IngestOrder>
     */
    public function findStuckAcrossCompanies(\DateTimeImmutable $orderedBefore, int $limit): array
    {
        /** @var list<IngestOrder> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.orderedAt < :orderedBefore')
            ->andWhere('o.refreshStoppedAt IS NULL')
            ->setParameter('statuses', self::nonTerminalStatuses())
            ->setParameter('orderedBefore', $orderedBefore)
            ->orderBy('o.orderedAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * @return list<string>
     */
    private static function nonTerminalStatuses(): array
    {
        return array_values(array_map(
            static fn (IngestOrderStatus $s): string => $s->value,
            array_filter(
                IngestOrderStatus::cases(),
                static fn (IngestOrderStatus $s): bool => !$s->isTerminal(),
            ),
        ));
    }

    public function save(IngestOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }
}
