<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdRawDocumentRepository extends ServiceEntityRepository implements AdRawDocumentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdRawDocument::class);
    }

    public function countByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?AdRawDocumentStatus $statusFilter = null,
    ): int {
        $sql = 'SELECT COUNT(*) FROM marketplace_ad_raw_documents
                WHERE company_id = :company_id
                  AND marketplace = :marketplace
                  AND report_date BETWEEN :from AND :to';

        $params = [
            'company_id' => $companyId,
            'marketplace' => $marketplace,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];

        if (null !== $statusFilter) {
            $sql .= ' AND status = :status';
            $params['status'] = $statusFilter->value;
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Raw-SQL UPDATE минуя UoW — безопасно вызывать из async-обработчика.
     * Идемпотентно: повторный вызов на уже FAILED документе вернёт 0.
     *
     * @return int affected rows (1 — успешно, 0 — уже FAILED или IDOR)
     */
    public function markFailedWithReason(string $documentId, string $companyId, string $reason): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE marketplace_ad_raw_documents
             SET status = :status, processing_error = :reason, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id AND status != :status',
            [
                'status' => AdRawDocumentStatus::FAILED->value,
                'reason' => $reason,
                'id' => $documentId,
                'company_id' => $companyId,
            ],
        );
    }

    public function findByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.reportDate BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('r.reportDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(AdRawDocument $document): void
    {
        $this->getEntityManager()->persist($document);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?AdRawDocument
    {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findByMarketplaceAndDate(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $reportDate,
    ): ?AdRawDocument {
        return $this->findOneBy([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'reportDate' => $reportDate,
        ]);
    }

    /**
     * Идемпотентный lookup по метке `batch_id=<uuid>\nfilename=<name>\n`
     * в префиксе `raw_payload` (Task-12-test).
     *
     * Используется {@see \App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction}:
     * повторный клик «Обработать» не должен создавать второй документ для той же
     * (company, batch, filename) — искомая пара однозначно идентифицирует CSV
     * внутри batch'а (filename = `<campaignId>_<from>-<to>.csv`).
     *
     * IDOR-guard: `companyId` в WHERE (фильтр до LIKE). Условие `raw_payload LIKE`
     * работает по префиксу (≈100 символов), таблица небольшая (сотни документов
     * на компанию) — запрос идёт по `idx_ad_raw_document_company` + фильтр LIKE.
     *
     * LIKE escape: `filename` содержит `_` (`<campaignId>_<from>-<to>.csv`),
     * который в SQL LIKE — wildcard «любой символ». Экранируем `%`, `_`, `\`
     * через {@see self::escapeLike()}: PostgreSQL LIKE по умолчанию считает `\`
     * escape-символом (без явного `ESCAPE` в запросе), поэтому достаточно
     * passed-через-параметр `\_` / `\%` / `\\`.
     */
    public function findByBatchAndFilename(
        string $companyId,
        string $batchId,
        string $filename,
    ): ?AdRawDocument {
        $prefix = sprintf("batch_id=%s\nfilename=%s\n", $batchId, $filename);

        /** @var AdRawDocument|null $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.rawPayload LIKE :prefix')
            ->setParameter('companyId', $companyId)
            ->setParameter('prefix', $this->escapeLike($prefix).'%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Экранирование LIKE-метасимволов (`%`, `_`, `\`) в префиксе для
     * {@see self::findByBatchAndFilename()}. PostgreSQL LIKE по умолчанию
     * трактует `\` как escape — дополнительный ESCAPE-clause не нужен,
     * достаточно удвоить backslash и префиксировать `%`/`_`.
     */
    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /**
     * @return AdRawDocument[]
     */
    public function findDrafts(string $companyId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', AdRawDocumentStatus::DRAFT)
            ->orderBy('r.reportDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Стримит raw-документы по произвольной комбинации фильтров. Все параметры опциональны:
     * любой из них может быть null — тогда ограничение по этому полю не накладывается.
     * Используется reprocess-командой для массовой переобработки.
     *
     * Реализовано через {@see \Doctrine\ORM\AbstractQuery::toIterable()} — Doctrine
     * читает строки из курсора БД по одной, не материализуя весь результат в памяти.
     * Это одновременно:
     *  - ограничивает peak memory usage при `reprocess --all` с десятками тысяч документов;
     *  - позволяет команде безопасно вызывать EntityManager::clear() между батчами —
     *    следующая yield-нутая entity будет свежей managed-entity, а не detached-объектом
     *    из предзагруженного массива (см. issue #1495/codex-review P1).
     *
     * @return iterable<AdRawDocument>
     */
    public function streamByFilters(
        ?string $companyId = null,
        ?string $marketplace = null,
        ?\DateTimeImmutable $reportDate = null,
    ): iterable {
        $qb = $this->createQueryBuilder('r')->orderBy('r.reportDate', 'DESC');

        if (null !== $companyId) {
            $qb->andWhere('r.companyId = :companyId')->setParameter('companyId', $companyId);
        }

        if (null !== $marketplace) {
            $qb->andWhere('r.marketplace = :marketplace')->setParameter('marketplace', $marketplace);
        }

        if (null !== $reportDate) {
            $qb->andWhere('r.reportDate = :reportDate')->setParameter('reportDate', $reportDate);
        }

        return $qb->getQuery()->toIterable();
    }
}
