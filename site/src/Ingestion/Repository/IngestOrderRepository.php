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
     * Заказы батча по внешним идентификаторам.
     *
     * `$forUpdate` включает `SELECT ... FOR UPDATE` с принудительным
     * перечитыванием: путей записи статуса два — нормализация и перепрос, — и
     * односторонняя блокировка не защищает. Writer, прочитавший строку до
     * блокировки, всё равно перезапишет её своим отложенным UPDATE, а
     * блокировка лишь задержит его. Порядок по `id` задаёт единый порядок
     * захвата: без него два батча с пересекающимися заказами встали бы во
     * взаимную блокировку.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, IngestOrder> externalId => заказ
     */
    public function findManyByExternalIdsIndexed(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        array $externalIds,
        bool $forUpdate = false,
    ): array {
        if ([] === $externalIds) {
            return [];
        }

        $query = $this->createQueryBuilder('o')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.source = :source')
            ->andWhere('o.connectionRef = :connectionRef')
            ->andWhere('o.externalId IN (:externalIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('externalIds', $externalIds)
            ->orderBy('o.id', 'ASC')
            ->getQuery();

        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }

        /** @var list<IngestOrder> $orders */
        $orders = $query->getResult();

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
            // Заказ без сырья остановить нечем: проблема привязывается к
            // сырью, а остановка без видимой очереди — тихая потеря. Пропускать
            // его уже ПОСЛЕ выборки нельзя: он остаётся с пустым
            // refreshStoppedAt и вечно занимает начало следующей, блокируя
            // остановку всех остальных.
            ->andWhere('o.lastRawRecordId IS NOT NULL')
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
            ->andWhere('o.lastRawRecordId IS NOT NULL')
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
     * Сколько зависших заказов остановить нечем — у них нет сырья, к которому
     * привязывается проблема.
     *
     * @companyScopeExempt Аномалия считается по всем компаниям тем же
     * системным прогоном, что и остановка зависших. Состояние недостижимое —
     * заказ всегда создаётся нормализацией, — но если оно всё же появится,
     * молчать о нём нельзя: заказ выпал бы и из опроса, и из очереди на
     * разбор.
     */
    public function countStuckWithoutRawRecord(\DateTimeImmutable $orderedBefore, ?string $companyId = null): int
    {
        $qb = $this->createQueryBuilder('o');

        // Прогон с `--company-id` не должен считать чужие компании: иначе в
        // логе появился бы межтенантный агрегат там, где запрошена одна
        // компания.
        if (null !== $companyId) {
            $qb->andWhere('o.companyId = :companyId')->setParameter('companyId', $companyId);
        }

        /** @var int $count */
        $count = $qb
            ->select('COUNT(o.id)')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.orderedAt < :orderedBefore')
            ->andWhere('o.refreshStoppedAt IS NULL')
            ->andWhere('o.lastRawRecordId IS NULL')
            ->setParameter('statuses', self::nonTerminalStatuses())
            ->setParameter('orderedBefore', $orderedBefore)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Номера маркетплейса, встречающиеся у ДВУХ и более заказов подключения.
     *
     * Коллизию нельзя искать внутри уже ограниченной страницы очереди: при
     * `--limit=1` два заказа с одним номером попадают в разные прогоны и
     * никогда не встречаются, а значит оба получают статус одного и того же
     * заказа маркетплейса. Проверять нужно по всему подключению.
     *
     * Спрашивается ровно про номера ТЕКУЩЕЙ страницы очереди, а считаются они
     * по всему подключению. Слепой потолок на число групп был бы хуже
     * бесполезного: конфликт за его пределами выглядел бы безопасным, и один
     * заказ молча заменил бы другой. Размер выборки при этом ограничен
     * страницей очереди.
     *
     * Область та же, что у очереди: остановленные и терминальные заказы не
     * опрашиваются, и конфликт между ними ни на что не влияет.
     *
     * @param list<string> $externalOrderIds номера заказов текущей страницы
     *
     * @return list<string>
     */
    public function findDuplicateExternalOrderIds(
        string $companyId,
        IngestSource $source,
        string $connectionRef,
        array $externalOrderIds,
    ): array {
        if ([] === $externalOrderIds) {
            return [];
        }

        /** @var list<array{externalOrderId: string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.externalOrderId AS externalOrderId')
            ->andWhere('o.companyId = :companyId')
            ->andWhere('o.source = :source')
            ->andWhere('o.connectionRef = :connectionRef')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.refreshStoppedAt IS NULL')
            ->andWhere('o.externalOrderId IN (:externalOrderIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('statuses', self::nonTerminalStatuses())
            ->setParameter('externalOrderIds', array_values(array_unique($externalOrderIds)))
            ->groupBy('o.externalOrderId')
            ->having('COUNT(o.id) > 1')
            ->orderBy('o.externalOrderId', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['externalOrderId'], $rows);
    }

    /**
     * Заказы ВСЕХ компаний под блокировкой записи — для системного прохода по
     * зависшим.
     *
     * @companyScopeExempt Кандидаты уже отобраны `findStuckAcrossCompanies()`,
     * который проходит по всем компаниям: зависание не зависит от того, живо
     * ли ещё подключение. Разбивать блокировку по компаниям значило бы
     * выполнить запрос на компанию — прямой N+1 в часовом cron-пути. Компания
     * проверяется там, где создаётся проблема: у каждой берётся своя.
     *
     * @param list<string> $orderIds
     *
     * @return list<IngestOrder>
     */
    public function findManyForUpdateAcrossCompanies(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        /** @var list<IngestOrder> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.id IN (:orderIds)')
            ->setParameter('orderIds', array_values(array_unique($orderIds)))
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
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
