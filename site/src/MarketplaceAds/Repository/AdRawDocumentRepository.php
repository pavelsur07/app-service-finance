<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdRawDocument::class);
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
     * Количество AdRawDocument компании по площадке в диапазоне дат включительно.
     *
     * Используется как «итог» для финализации AdLoadJob: condition «все документы
     * дошли до терминального состояния» — это
     * `countByCompanyMarketplaceAndDateRange == countTerminalByCompanyMarketplaceAndDateRange`.
     * COUNT по AdRawDocument идемпотентен благодаря UniqueConstraint
     * (company_id, marketplace, report_date): повторный upsert за ту же дату
     * не создаст новую строку.
     */
    public function countByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): int {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.companyId = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.reportDate BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Количества PROCESSED и FAILED документов в диапазоне — одним SELECT'ом
     * с GROUP BY status. Альтернатива двум отдельным COUNT-запросам.
     *
     * Используется {@see \App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler::tryFinalizeJob}:
     *  - total == processed + failed → chunks closed AND все документы терминальны;
     *  - failed == 0 → markCompleted, иначе markFailed с reason.
     *
     * Возврат: массив с гарантированными ключами 'processed' и 'failed' (0, если
     * соответствующих записей нет — не приходится проверять `?? 0` на вызове).
     *
     * @return array{processed: int, failed: int}
     */
    public function countTerminalByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS cnt')
            ->where('r.companyId = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.reportDate BETWEEN :from AND :to')
            ->andWhere('r.status IN (:terminal)')
            ->groupBy('r.status')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('terminal', [AdRawDocumentStatus::PROCESSED, AdRawDocumentStatus::FAILED])
            ->getQuery()
            ->getArrayResult();

        $counts = ['processed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof AdRawDocumentStatus ? $row['status'] : AdRawDocumentStatus::from((string) $row['status']);
            if (AdRawDocumentStatus::PROCESSED === $status) {
                $counts['processed'] = (int) $row['cnt'];
            } elseif (AdRawDocumentStatus::FAILED === $status) {
                $counts['failed'] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * Идемпотентно помечает документ как FAILED с причиной через raw DBAL UPDATE.
     *
     * Условие `status != 'failed'` делает операцию повторно-безопасной: retry
     * Messenger'а после уже-FAILED не перезапишет processing_error (первая
     * причина сохраняется). Параллельный worker, успевший отработать документ
     * до ошибки в нашем — проходит Action и markAsProcessed; после этого наш
     * markFailedWithReason затронет 0 строк (WHERE не сойдётся: status =
     * 'processed' ≠ 'failed' — НО мы не хотим «понижать» PROCESSED до FAILED).
     * Поэтому guard сильнее: `status = 'draft'`.
     *
     * `company_id` в WHERE — встроенный IDOR-guard.
     *
     * @return int число обновлённых строк (0 если документ уже терминальный или чужой)
     */
    public function markFailedWithReason(string $id, string $companyId, string $reason): int
    {
        if ('' === $reason) {
            throw new \InvalidArgumentException('Причина ошибки не может быть пустой.');
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_raw_documents
                SET status = 'failed',
                    processing_error = :reason,
                    updated_at = NOW()
                WHERE id = :id
                  AND company_id = :companyId
                  AND status = 'draft'
                SQL,
            [
                'reason' => $reason,
                'id' => $id,
                'companyId' => $companyId,
            ],
        );
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
