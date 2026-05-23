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
     * Последние задания компании по маркетплейсу, отсортированные по createdAt DESC,
     * ограничение $limit.
     *
     * @return list<AdLoadJob>
     */
    public function findRecentByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        int $limit = 20,
    ): array;

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

    /**
     * Помечает задание как PARTIAL_SUCCESS — часть батчей прошла, часть нет.
     * Используется финализатором cron-driven pipeline (Task-11.7).
     *
     * Идемпотентно (status IN pending/running), IDOR-safe, пишет $reason в
     * `failure_reason` (поле переиспользуется — хранит объяснение терминального
     * не-OK исхода).
     *
     * @return int число обновлённых строк (0 если job уже терминальный или чужой)
     */
    public function markPartialSuccess(string $jobId, string $companyId, string $reason): int;

    /**
     * Все job'ы в статусе RUNNING (глобально, без company-фильтра — cron
     * работает cross-tenant). Используется финализатором Task-11.7 для
     * сканирования кандидатов на закрытие.
     *
     * @return list<AdLoadJob>
     */
    public function findAllRunning(): array;

    /**
     * Существует ли хотя бы один job на точно этот (company, marketplace,
     * dateFrom, dateTo)? Используется daily-sync cron для идемпотентности:
     * повторный запуск в тот же день не должен создать второй job за вчера.
     *
     * Проверяется весь жизненный цикл (любой статус), т.к. факт «job за эту
     * дату уже создавался» — повод не создавать дубликат, даже если
     * предыдущая попытка закончилась FAILED.
     */
    public function existsByDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): bool;

    public function findLatestJobCoveringDate(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
    ): ?AdLoadJob;

    public function findCompletedJobCoveringDate(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
    ): ?AdLoadJob;
}
