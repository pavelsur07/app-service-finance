<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestRawRecord>
 */
final class IngestRawRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestRawRecord::class);
    }

    public function findOneByIdAndCompany(string $companyId, string $rawRecordId): ?IngestRawRecord
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.id = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIdAndCompany(string $rawRecordId, string $companyId): ?IngestRawRecord
    {
        return $this->findOneByIdAndCompany($companyId, $rawRecordId);
    }

    public function findLatestByCompanySourceExternalId(
        string $companyId,
        IngestSource $source,
        string $resourceType,
        string $externalId,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.resourceType = :resourceType')
            ->andWhere('record.externalId = :externalId')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalId', $externalId)
            ->orderBy('record.fetchedAt', 'DESC')
            ->addOrderBy('record.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCompanySourceExternalIdAndHash(
        string $companyId,
        IngestSource $source,
        string $resourceType,
        string $externalId,
        string $hash,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.resourceType = :resourceType')
            ->andWhere('record.externalId = :externalId')
            ->andWhere('record.hash = :hash')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalId', $externalId)
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<IngestRawRecord>
     */
    public function findStuckPending(\DateTimeImmutable $olderThan, int $limit): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('record')
            ->andWhere('record.normalizationStatus = :status')
            ->andWhere('record.fetchedAt < :olderThan')
            ->setParameter('status', RawNormalizationStatus::PENDING->value)
            ->setParameter('olderThan', $olderThan)
            ->orderBy('record.fetchedAt', 'ASC')
            ->addOrderBy('record.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Сырьё, чью нагрузку пора удалить: не встречалось дольше окна хранения,
     * ещё не очищено и не служит доказательством ни для одной НЕРАЗОБРАННОЙ
     * проблемы.
     *
     * Возраст считается по `lastSeenAt`, а не по `fetchedAt`. Дедуп при
     * часовом опросе не создаёт новую запись, а обновляет отметку «видели»:
     * запись, скачанную год назад, но подтверждаемую каждый час, удалять
     * бессмысленно — следующий же прогон запишет её заново под новым
     * идентификатором. Свежесть — это когда последний раз видели, а не когда
     * впервые скачали.
     *
     * Сырьё открытой проблемы не удаляется: оно и есть то, с чего начинает
     * разбирающий. Состояние не вечное — проблему можно закрыть, и тогда
     * запись уйдёт следующим прогоном.
     *
     * @companyScopeExempt Обслуживание всего хранилища: политика хранения
     * общая, а не покомпанейская, и ограничивать выборку одной компанией
     * здесь нечем.
     *
     * @return list<IngestRawRecord>
     */
    public function findPrunable(\DateTimeImmutable $notSeenSince, int $limit): array
    {
        /** @var list<IngestRawRecord> $records */
        $records = $this->prunableQueryBuilder($notSeenSince)
            ->orderBy('record.lastSeenAt', 'ASC')
            ->addOrderBy('record.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $records;
    }

    /**
     * Сколько записей удержано открытыми проблемами.
     *
     * Считается отдельно, чтобы удержание было видно: иначе «нечего удалять» и
     * «удалять нельзя» выглядели бы одинаково, а это разные состояния с разной
     * реакцией.
     *
     * @companyScopeExempt См. {@see findPrunable()}.
     */
    public function countHeldByUnresolvedIssues(\DateTimeImmutable $notSeenSince): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('record')
            ->select('COUNT(record.id)')
            ->andWhere('record.lastSeenAt < :notSeenSince')
            ->andWhere('record.payloadPrunedAt IS NULL')
            ->andWhere($this->unresolvedIssueExists())
            ->setParameter('notSeenSince', $notSeenSince)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Одна запись под блокировкой записи, с принудительным перечитыванием.
     *
     * Нужна дедупу: retention и повторная загрузка правят одну и ту же строку
     * и один и тот же объект, и без общей блокировки их шаги переплетаются —
     * восстановление снимало отметку, а retention следом удалял объект,
     * оставляя запись, которая утверждает, что нагрузка на месте.
     *
     * `HINT_REFRESH` нужен тем, кто принимает решение по прочитанным полям.
     * Тем, кто уже правит сущность, он вреден: перечитывание затирает
     * незафлашенные изменения. См. параметр `$refresh`.
     */
    public function findOneForUpdate(string $companyId, string $rawRecordId, bool $refresh = true): ?IngestRawRecord
    {
        $query = $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.id = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE);

        // Перечитывание выключается там, где вызывающий УЖЕ правит эту
        // сущность: `HINT_REFRESH` затирает незафлашенные изменения, и
        // нормализация теряла бы отметку о завершении разбора. Блокировку это
        // не ослабляет — сериализацию даёт `FOR UPDATE`, а не перечитывание;
        // без него можно лишь увидеть чуть более старое значение полей.
        if ($refresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }

        /** @var IngestRawRecord|null $record */
        $record = $query->getOneOrNullResult();

        return $record;
    }

    /**
     * Кандидаты под блокировкой записи, с принудительным перечитыванием.
     *
     * Между выборкой и удалением проходит время, и за него дедуп мог обновить
     * `lastSeenAt`, а нормализация — завести проблему на эту запись. Удалить её
     * после этого значило бы необратимо потерять свежее сырьё или единственное
     * доказательство. `HINT_REFRESH` обязателен: без него вернутся значения,
     * прочитанные ДО транзакции, и блокировка защитит строку в базе, оставив в
     * памяти устаревшее состояние.
     *
     * @companyScopeExempt См. {@see findPrunable()}: политика хранения общая.
     *
     * @param list<string> $ids
     *
     * @return list<IngestRawRecord>
     */
    public function findManyPrunableForUpdate(array $ids, \DateTimeImmutable $notSeenSince): array
    {
        if ([] === $ids) {
            return [];
        }

        // Условие про открытые проблемы здесь НЕ проверяется — намеренно.
        //
        // `FOR UPDATE` блокирует строку сырья, но не отсутствие строки в
        // таблице проблем: при READ COMMITTED подзапрос `NOT EXISTS` остаётся
        // снимком, и конкурент, вставивший проблему сразу после проверки,
        // остался бы незамеченным. Поэтому блокировка берётся по свежести, а
        // удержание перепроверяется отдельным запросом УЖЕ ПОД блокировкой —
        // см. {@see filterHeldByUnresolvedIssues()}.
        /** @var list<IngestRawRecord> $records */
        $records = $this->createQueryBuilder('record')
            ->andWhere('record.lastSeenAt < :notSeenSince')
            ->andWhere('record.payloadPrunedAt IS NULL')
            ->andWhere('record.id IN (:ids)')
            ->setParameter('notSeenSince', $notSeenSince)
            ->setParameter('ids', array_values(array_unique($ids)))
            ->orderBy('record.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $records;
    }

    /**
     * Записи с принятым решением, но ещё не удалённым объектом.
     *
     * Незавершённая очистка: решение закоммичено, а до хранилища прогон не
     * дошёл — упал, был убит или не успел. Без этой выборки объект остался бы
     * навсегда: кандидатов ищут среди НЕпомеченных, и такая строка туда уже
     * не попадает.
     *
     * @companyScopeExempt См. {@see findPrunable()}: политика хранения общая.
     *
     * @return list<IngestRawRecord>
     */
    public function findPendingPayloadDeletion(int $limit): array
    {
        /** @var list<IngestRawRecord> $records */
        $records = $this->createQueryBuilder('record')
            ->andWhere('record.payloadPrunedAt IS NOT NULL')
            ->andWhere('record.payloadDeletedAt IS NULL')
            ->orderBy('record.payloadPrunedAt', 'ASC')
            ->addOrderBy('record.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $records;
    }

    /**
     * Те же записи под блокировкой, с принудительным перечитыванием.
     *
     * Повторная выгрузка могла вернуть нагрузку и снять отметки, пока прогон
     * шёл к удалению объекта: перечитать состояние под блокировкой — это
     * единственный способ не удалить только что восстановленное.
     *
     * @companyScopeExempt См. {@see findPrunable()}: политика хранения общая.
     *
     * @param list<string> $ids
     *
     * @return list<IngestRawRecord>
     */
    public function findPendingPayloadDeletionForUpdate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<IngestRawRecord> $records */
        $records = $this->createQueryBuilder('record')
            ->andWhere('record.payloadPrunedAt IS NOT NULL')
            ->andWhere('record.payloadDeletedAt IS NULL')
            ->andWhere('record.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->orderBy('record.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $records;
    }

    /**
     * Какие из этих записей удерживаются НЕРАЗОБРАННОЙ проблемой.
     *
     * Спрашивается уже под блокировкой строк сырья и отдельным запросом:
     * `READ COMMITTED` видит здесь всё, что успело закоммититься к началу
     * ЭТОГО запроса, тогда как условие внутри блокирующей выборки осталось бы
     * снимком её собственного момента.
     *
     * @companyScopeExempt См. {@see findPrunable()}: политика хранения общая.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function filterHeldByUnresolvedIssues(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{rawRecordId: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT issue.rawRecordId AS rawRecordId')
            ->from(NormalizationIssue::class, 'issue')
            ->andWhere('issue.rawRecordId IN (:ids)')
            ->andWhere('issue.resolvedAt IS NULL')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['rawRecordId'], $rows);
    }

    private function prunableQueryBuilder(\DateTimeImmutable $notSeenSince): QueryBuilder
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.lastSeenAt < :notSeenSince')
            ->andWhere('record.payloadPrunedAt IS NULL')
            ->andWhere(sprintf('NOT (%s)', $this->unresolvedIssueExists()))
            ->setParameter('notSeenSince', $notSeenSince);
    }

    private function unresolvedIssueExists(): string
    {
        $issues = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(NormalizationIssue::class, 'issue')
            ->andWhere('issue.rawRecordId = record.id')
            ->andWhere('issue.resolvedAt IS NULL')
            ->getDQL();

        return sprintf('EXISTS (%s)', $issues);
    }
}
