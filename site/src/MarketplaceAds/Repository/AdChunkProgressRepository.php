<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdChunkProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * Хранение записей о выгруженных чанках AdLoadJob'а.
 *
 * tryMarkCompleted — единственный hot-path метод: raw DBAL
 * `INSERT ... ON CONFLICT DO NOTHING`, минуя Doctrine UoW (чтобы параллельные
 * воркеры не боролись за identity map). UNIQUE (job_id, date_from, date_to)
 * на уровне таблицы делает учёт атомарным и race-safe.
 */
class AdChunkProgressRepository extends ServiceEntityRepository implements AdChunkProgressRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdChunkProgress::class);
    }

    public function tryMarkCompleted(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): bool {
        // Нормализация до 00:00: date_immutable хранит только дату, но сам
        // \DateTimeImmutable несёт время — без нормализации PDO мог бы
        // закинуть 'Y-m-d H:i:s' и попасть мимо колонки date.
        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        $connection = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affectedRows = (int) $connection->executeStatement(
            <<<'SQL'
                INSERT INTO marketplace_ad_chunk_progress
                    (id, job_id, company_id, date_from, date_to, completed_at, created_at)
                VALUES
                    (:id, :jobId, :companyId, :dateFrom, :dateTo, :completedAt, :createdAt)
                ON CONFLICT (job_id, date_from, date_to) DO NOTHING
                SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'jobId' => $jobId,
                'companyId' => $companyId,
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateTo->format('Y-m-d'),
                'completedAt' => $now,
                'createdAt' => $now,
            ],
        );

        return $affectedRows > 0;
    }
}
