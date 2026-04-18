<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;

/**
 * Контракт репозитория AdLoadJob.
 *
 * Вынесен в интерфейс, чтобы:
 *  - держать concrete-репозиторий `final` (правило CLAUDE.md) без потери возможности
 *    мокать в unit-тестах хендлеров — PHPUnit мокает интерфейс без подклассов;
 *  - зафиксировать явный контракт чтения/мутаций счётчиков и жизненного цикла
 *    для Messenger-хендлеров и Controller/Action'ов.
 *
 * Атомарные инкременты (*_days, chunks_completed) и mark* реализованы на уровне
 * repository через raw DBAL UPDATE — минуя Doctrine UoW, чтобы параллельные
 * воркеры не затирали друг друга. Все мутации идемпотентны через SQL guard'ы.
 */
interface AdLoadJobRepositoryInterface
{
    public function save(AdLoadJob $job): void;

    public function findByIdAndCompany(string $id, string $companyId): ?AdLoadJob;

    /**
     * Находит задание по ID БЕЗ проверки company_id.
     *
     * IDOR-safe только в trusted-контексте (Messenger-хендлеры, где jobId сгенерирован
     * внутри системы). Для HTTP-запросов использовать {@see self::findByIdAndCompany()}.
     */
    public function find(string $id): ?AdLoadJob;

    public function findLatestActiveJobByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): ?AdLoadJob;

    public function findActiveJobCoveringDate(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
    ): ?AdLoadJob;

    public function incrementLoadedDays(string $jobId, string $companyId, int $delta = 1): int;

    public function incrementProcessedDays(string $jobId, string $companyId, int $delta = 1): int;

    public function incrementFailedDays(string $jobId, string $companyId, int $delta = 1): int;

    public function incrementChunksCompleted(string $jobId, string $companyId, int $delta = 1): int;

    /**
     * Идемпотентно помечает задание как COMPLETED. SQL guard `status IN ('pending','running')`
     * защищает от повторной финализации (два воркера дошли до tryFinalizeJob одновременно).
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markCompleted(string $jobId, string $companyId): int;

    /**
     * Идемпотентно помечает задание как FAILED с причиной. Повторные вызовы не
     * перезаписывают исходную причину (SQL guard на статус).
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markFailed(string $jobId, string $companyId, string $reason): int;
}
