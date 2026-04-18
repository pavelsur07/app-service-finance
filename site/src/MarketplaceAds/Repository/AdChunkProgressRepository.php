<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdChunkProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * Репозиторий {@see AdChunkProgress} — идемпотентная фиксация факта
 * завершения чанка и подсчёт выполненных чанков для задания.
 *
 * Реализован поверх DBAL (минуя Doctrine UoW), чтобы параллельные
 * Messenger-воркеры могли безопасно писать строки в одну таблицу:
 *  - `INSERT ... ON CONFLICT DO NOTHING` делает запись идемпотентной
 *    на уровне БД (см. UNIQUE `uq_ad_chunk_progress_job_dates`);
 *  - `SELECT COUNT(*)` читает актуальное состояние без участия UoW.
 */
final class AdChunkProgressRepository extends ServiceEntityRepository implements AdChunkProgressRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdChunkProgress::class);
    }

    public function markChunkCompleted(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): bool {
        $this->assertJobBelongsToCompany($jobId, $companyId);

        // Нормализуем до начала суток — симметрично AdChunkProgress::__construct
        // и AdLoadJob::__construct. Колонка date_from/date_to имеет тип DATE,
        // но явная нормализация делает поведение независимым от настроек драйвера.
        $dateFromNorm = $dateFrom->setTime(0, 0)->format('Y-m-d');
        $dateToNorm = $dateTo->setTime(0, 0)->format('Y-m-d');

        $affected = (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO marketplace_ad_chunk_progress
                    (id, job_id, date_from, date_to, completed_at)
                VALUES
                    (:id, :jobId, :dateFrom, :dateTo, NOW())
                ON CONFLICT (job_id, date_from, date_to) DO NOTHING
                SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'jobId' => $jobId,
                'dateFrom' => $dateFromNorm,
                'dateTo' => $dateToNorm,
            ],
        );

        // Postgres возвращает число фактически вставленных строк:
        // 1 — впервые, 0 — конфликт по UNIQUE (чанк уже отмечен).
        return 1 === $affected;
    }

    public function countCompletedChunks(string $jobId, string $companyId): int
    {
        $this->assertJobBelongsToCompany($jobId, $companyId);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ad_chunk_progress WHERE job_id = :jobId',
            ['jobId' => $jobId],
        );
    }

    /**
     * IDOR-guard: отдельный SELECT к marketplace_ad_load_jobs.
     *
     * В отличие от `WHERE id = :jobId AND company_id = :companyId` прямо
     * в INSERT/SELECT, здесь чанк-таблица не хранит company_id. Поэтому
     * принадлежность проверяется через родительскую marketplace_ad_load_jobs;
     * при несоответствии — явный \DomainException (а не тихие 0 строк).
     */
    private function assertJobBelongsToCompany(string $jobId, string $companyId): void
    {
        $found = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM marketplace_ad_load_jobs WHERE id = :jobId AND company_id = :companyId',
            [
                'jobId' => $jobId,
                'companyId' => $companyId,
            ],
        );

        if (false === $found) {
            throw new \DomainException(sprintf(
                'AdLoadJob %s не найден или не принадлежит компании %s.',
                $jobId,
                $companyId,
            ));
        }
    }
}
