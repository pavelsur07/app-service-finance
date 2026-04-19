<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdLoadJobRepository extends ServiceEntityRepository implements AdLoadJobRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdLoadJob::class);
    }

    public function save(AdLoadJob $job): void
    {
        $this->getEntityManager()->persist($job);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?AdLoadJob
    {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    /**
     * Находит задание по ID БЕЗ проверки company_id.
     *
     * IDOR-safe только в trusted-контексте: Messenger-хендлерах, где ID был
     * сгенерирован внутри системы (см. LoadOzonAdStatisticsRangeHandler — в
     * Message приходит только jobId). Для любого вызова, исходящего из
     * HTTP-запроса, использовать {@see self::findByIdAndCompany()}.
     */
    public function find($id, $lockMode = null, $lockVersion = null): ?AdLoadJob
    {
        return parent::find($id, $lockMode, $lockVersion);
    }

    public function findLatestActiveJobByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): ?AdLoadJob {
        return $this->createQueryBuilder('j')
            ->where('j.companyId = :companyId')
            ->andWhere('j.marketplace = :marketplace')
            ->andWhere('j.status IN (:activeStatuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('activeStatuses', [AdLoadJobStatus::PENDING, AdLoadJobStatus::RUNNING])
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveJobCoveringDate(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
    ): ?AdLoadJob {
        return $this->createQueryBuilder('j')
            ->where('j.companyId = :companyId')
            ->andWhere('j.marketplace = :marketplace')
            ->andWhere('j.status IN (:activeStatuses)')
            ->andWhere('j.dateFrom <= :date')
            ->andWhere('j.dateTo >= :date')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('activeStatuses', [AdLoadJobStatus::PENDING, AdLoadJobStatus::RUNNING])
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Атомарно увеличивает счётчик loaded_days на $delta для задания, принадлежащего компании.
     *
     * Реализовано через raw DBAL `UPDATE ... SET loaded_days = loaded_days + :delta`,
     * минуя Doctrine UoW: параллельные Messenger-воркеры могут безопасно инкрементировать
     * один и тот же счётчик без read-modify-write race (UoW загрузил бы entity в своё
     * состояние и перезаписал поле последним значением, потеряв инкременты соседей).
     *
     * `company_id` в WHERE — дополнительный IDOR-guard: если jobId не принадлежит
     * переданной компании, UPDATE затронет 0 строк и метод вернёт 0.
     *
     * @return int число обновлённых строк (0 или 1)
     */
    public function incrementLoadedDays(string $jobId, string $companyId, int $delta = 1): int
    {
        return $this->atomicIncrement('loaded_days', $jobId, $companyId, $delta);
    }

    public function incrementProcessedDays(string $jobId, string $companyId, int $delta = 1): int
    {
        return $this->atomicIncrement('processed_days', $jobId, $companyId, $delta);
    }

    public function incrementFailedDays(string $jobId, string $companyId, int $delta = 1): int
    {
        return $this->atomicIncrement('failed_days', $jobId, $companyId, $delta);
    }

    /**
     * Помечает задание как FAILED через raw DBAL UPDATE (минуя UoW).
     *
     * Условие `status IN ('pending', 'running')` делает операцию идемпотентной:
     * если задание уже в терминальном статусе (FAILED/COMPLETED), UPDATE затронет
     * 0 строк и причина не перезапишется — повторные вызовы от разных воркеров
     * не «сбрасывают» исходную причину ошибки.
     *
     * `company_id` в WHERE — встроенный IDOR-guard.
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markFailed(string $jobId, string $companyId, string $reason): int
    {
        if ('' === $reason) {
            throw new \InvalidArgumentException('Причина ошибки не может быть пустой.');
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_load_jobs
                SET status = 'failed',
                    failure_reason = :reason,
                    finished_at = NOW(),
                    updated_at = NOW()
                WHERE id = :jobId
                  AND company_id = :companyId
                  AND status IN ('pending', 'running')
                SQL,
            [
                'reason' => $reason,
                'jobId' => $jobId,
                'companyId' => $companyId,
            ],
        );
    }

    /**
     * Помечает задание как COMPLETED через raw DBAL UPDATE (минуя UoW).
     *
     * Симметрично {@see self::markFailed()}: условие `status IN ('pending', 'running')`
     * делает операцию идемпотентной — повторный вызов на уже терминальном задании
     * (COMPLETED/FAILED) затронет 0 строк и не перезапишет finished_at.
     *
     * `company_id` в WHERE — встроенный IDOR-guard.
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markCompleted(string $jobId, string $companyId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_load_jobs
                SET status = 'completed',
                    finished_at = NOW(),
                    updated_at = NOW()
                WHERE id = :jobId
                  AND company_id = :companyId
                  AND status IN ('pending', 'running')
                SQL,
            [
                'jobId' => $jobId,
                'companyId' => $companyId,
            ],
        );
    }

    private function atomicIncrement(string $column, string $jobId, string $companyId, int $delta): int
    {
        // Whitelist — защита от SQL-injection через имя колонки (параметризовать имя
        // колонки нельзя, только значение).
        if (!in_array($column, ['loaded_days', 'processed_days', 'failed_days'], true)) {
            throw new \InvalidArgumentException(sprintf('Недопустимое имя колонки: %s', $column));
        }

        // Счётчики монотонно растут; отрицательный/нулевой delta — это баг вызывающего кода,
        // который иначе тихо испортит прогресс и будет замаскирован clamp'ом в getProgress().
        if ($delta < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Инкремент должен быть >= 1, передано: %d',
                $delta,
            ));
        }

        $sql = sprintf(
            'UPDATE marketplace_ad_load_jobs SET %1$s = %1$s + :delta, updated_at = NOW() '
            . 'WHERE id = :jobId AND company_id = :companyId',
            $column,
        );

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'delta' => $delta,
            'jobId' => $jobId,
            'companyId' => $companyId,
        ]);
    }
}
