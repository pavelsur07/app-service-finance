<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Сырьё, которое пора удалить: не встречалось дольше окна хранения и не
     * служит доказательством ни для одной НЕРАЗОБРАННОЙ проблемы.
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
            ->andWhere($this->unresolvedIssueExists())
            ->setParameter('notSeenSince', $notSeenSince)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function remove(IngestRawRecord $record): void
    {
        $this->getEntityManager()->remove($record);
    }

    private function prunableQueryBuilder(\DateTimeImmutable $notSeenSince): QueryBuilder
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.lastSeenAt < :notSeenSince')
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
