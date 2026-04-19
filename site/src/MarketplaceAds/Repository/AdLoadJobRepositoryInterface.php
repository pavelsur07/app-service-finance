<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

interface AdLoadJobRepositoryInterface
{
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
}
