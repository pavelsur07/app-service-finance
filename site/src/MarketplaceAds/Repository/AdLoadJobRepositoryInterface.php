<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;

interface AdLoadJobRepositoryInterface
{
    /**
     * Загружает задание по ID с обязательной IDOR-проверкой company_id.
     */
    public function findByIdAndCompany(string $id, string $companyId): ?AdLoadJob;

    /**
     * Возвращает активный (PENDING/RUNNING) job, чей диапазон [dateFrom, dateTo]
     * включает $date. Используется для маппинга обработанного документа на
     * job, которому он принадлежит.
     */
    public function findActiveJobCoveringDate(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
    ): ?AdLoadJob;

    /**
     * Помечает задание как COMPLETED через raw DBAL UPDATE (минуя UoW).
     *
     * Реализация обязана:
     *  - ограничивать UPDATE `id = :id AND company_id = :companyId` (IDOR-guard);
     *  - допускать переход только из активных статусов (`pending`, `running`):
     *    повторный вызов на уже терминальном (COMPLETED/FAILED) задании должен
     *    вернуть 0 — операция идемпотентна на уровне SQL;
     *  - выставлять `finished_at = NOW()` и `updated_at = NOW()`.
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markCompleted(string $jobId, string $companyId): int;

    /**
     * Помечает задание как FAILED через raw DBAL UPDATE (минуя UoW).
     *
     * Идемпотентно (status IN pending/running) и IDOR-safe (company_id в WHERE);
     * повторный вызов от параллельного воркера не перезапишет исходную причину.
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markFailed(string $jobId, string $companyId, string $reason): int;
}
