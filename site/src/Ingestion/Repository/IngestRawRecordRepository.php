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
    /** Строк на один запрос проверки владения. */
    private const OWNERSHIP_LOOKUP_CHUNK = 500;

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
            // Ни разу не пробованные первыми, затем по давности попытки:
            // иначе неустранимый объект вечно занимал бы начало очереди, а
            // остальные не обрабатывались бы никогда.
            ->addSelect('CASE WHEN record.payloadDeletionAttemptedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN attemptRank')
            ->orderBy('attemptRank', 'ASC')
            ->addOrderBy('record.payloadDeletionAttemptedAt', 'ASC')
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
     * Взять блокировку записи на перечисленные строки сырья.
     *
     * Нужна тем, кто ПОТОМ пойдёт блокировать заказы. Порядок блокировок в
     * проекте один: сначала сырьё, потом заказы — его задаёт нормализация,
     * которая иначе не может (она начинает с сырья). Часовая остановка
     * зависших брала их в обратном порядке, и две транзакции складывались в
     * цикл: крон держал заказ и ждал сырьё, нормализатор держал сырьё и ждал
     * заказ. PostgreSQL разрывает такой цикл, убивая одну из транзакций, —
     * пачка остановки откатывалась, а часовой прогон падал.
     *
     * Порядок внутри пачки детерминированный по той же причине: два прогона,
     * взявшие одни и те же строки в разной последовательности, заблокировали
     * бы друг друга уже между собой.
     *
     * @companyScopeExempt Кандидаты уже отобраны запросом с ограничением по
     * компании; здесь берётся только блокировка на их сырьё, и разбивать её по
     * компаниям значило бы выполнить запрос на компанию — прямой N+1 в часовом
     * cron-пути.
     *
     * @param list<string> $ids
     *
     * @return list<string> идентификаторы строк, которые удалось заблокировать
     */
    public function lockManyForUpdate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: string}> $rows */
        $rows = $this->createQueryBuilder('record')
            ->select('record.id AS id')
            ->andWhere('record.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->orderBy('record.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return array_map(static fn (array $row): string => $row['id'], $rows);
    }

    /**
     * Кому принадлежит каждая из этих строк.
     *
     * Запрос НЕ блокирующий и стоит ПЕРЕД блокировкой намеренно. Блокировать
     * нужно в одном глобальном порядке — по идентификатору, — иначе пачка
     * проблем и прогон retention берут строки в разной последовательности и
     * складываются в цикл. Но глобальный порядок несовместим с фильтром по
     * компании внутри одного запроса, а блокировать чужую строку нельзя даже
     * на мгновение: это межтенантный побочный эффект, задерживающий чужой
     * ingestion. Компания у строки неизменяема, поэтому ответ не устаревает.
     *
     * Возвращается именно ПАРА, а не список идентификаторов. Список позволял
     * бы подделку: команда с чужой компанией и настоящим чужим
     * идентификатором проходила бы проверку заодно с честной командой того же
     * идентификатора.
     *
     * @companyScopeExempt Компания здесь и есть ответ, а не фильтр: метод
     * сообщает, кому принадлежит строка, и ровно на этом строится проверка
     * вызывающего.
     *
     * @param list<string> $ids
     *
     * @return array<string, string> идентификатор строки => её компания
     */
    public function ownersOf(array $ids): array
    {
        $owners = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::OWNERSHIP_LOOKUP_CHUNK) as $chunk) {
            /** @var list<array{id: string, companyId: string}> $rows */
            $rows = $this->createQueryBuilder('record')
                ->select('record.id AS id', 'record.companyId AS companyId')
                ->andWhere('record.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $owners[$row['id']] = $row['companyId'];
            }
        }

        return $owners;
    }

    /**
     * Заблокировать строки и прочитать их отметки ОДНИМ запросом.
     *
     * Пачка проблем заводится до тысячи штук за раз, и запрос отметок на
     * каждую был бы тем же N+1, ради устранения которого пачка и появилась:
     * блокировки при этом удерживаются до конца всех запросов.
     *
     * Порядок — по идентификатору, тот же глобальный, что и у
     * {@see lockManyForUpdate()}.
     *
     * @companyScopeExempt Принадлежность проверена до вызова —
     * см. {@see filterOwned()}; здесь берётся блокировка в глобальном порядке,
     * а фильтр по компании этот порядок разрушил бы.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{prunedAt: ?\DateTimeImmutable, deletedAt: ?\DateTimeImmutable}>
     */
    public function lockManyWithMarks(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: string, prunedAt: ?\DateTimeImmutable, deletedAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('record')
            ->select(
                'record.id AS id',
                'record.payloadPrunedAt AS prunedAt',
                'record.payloadDeletedAt AS deletedAt',
            )
            ->andWhere('record.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->orderBy('record.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $marks = [];
        foreach ($rows as $row) {
            $marks[$row['id']] = ['prunedAt' => $row['prunedAt'], 'deletedAt' => $row['deletedAt']];
        }

        return $marks;
    }

    /**
     * Отметки очистки СВЕЖИМ скалярным запросом, мимо карты идентичности.
     *
     * Вызывающий уже держит блокировку строки, но его сущность могла быть
     * загружена задолго до этого и правится прямо сейчас: перечитать её
     * значило бы затереть незафлашенные изменения. Скалярный запрос отвечает
     * на вопрос «что в базе» и ничего не трогает.
     *
     * @return array{prunedAt: ?\DateTimeImmutable, deletedAt: ?\DateTimeImmutable}|null
     */
    public function payloadMarks(string $companyId, string $rawRecordId): ?array
    {
        /** @var array{prunedAt: ?\DateTimeImmutable, deletedAt: ?\DateTimeImmutable}|null $row */
        $row = $this->createQueryBuilder('record')
            ->select('record.payloadPrunedAt AS prunedAt', 'record.payloadDeletedAt AS deletedAt')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.id = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Какие из этих записей удерживаются НЕРАЗОБРАННОЙ проблемой.
     *
     * Спрашивается уже под блокировкой строк сырья и отдельным запросом:
     * `READ COMMITTED` видит здесь всё, что успело закоммититься к началу
     * ЭТОГО запроса, тогда как условие внутри блокирующей выборки осталось бы
     * снимком её собственного момента.
     *
     * Компания сопоставляется явно. Предикат удержания обязан совпадать с
     * ключом блокировки, а блокируется пара `(компания, сырьё)` — см.
     * {@see findOneForUpdate()}. Проблема с чужим `companyId` этой блокировки
     * не брала, значит протокол её не сериализовал, и считать её удержанием
     * значило бы позволить одному арендатору тормозить очистку другого.
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
        $rows = $this->createQueryBuilder('record')
            ->select('DISTINCT record.id AS rawRecordId')
            ->andWhere('record.id IN (:ids)')
            ->andWhere($this->unresolvedIssueExists())
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

    /**
     * Удержание — это НЕРАЗОБРАННАЯ проблема ТОЙ ЖЕ компании на эту строку.
     *
     * Компания в условии не декоративна: блокировка берётся по паре
     * `(компания, сырьё)`, и предикат удержания обязан совпадать с ключом
     * блокировки — иначе часть удержаний оказывается вне протокола, который их
     * должен был сериализовать.
     */
    private function unresolvedIssueExists(): string
    {
        $issues = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(NormalizationIssue::class, 'issue')
            ->andWhere('issue.rawRecordId = record.id')
            ->andWhere('issue.companyId = record.companyId')
            ->andWhere('issue.resolvedAt IS NULL')
            ->getDQL();

        return sprintf('EXISTS (%s)', $issues);
    }
}
