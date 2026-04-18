<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

interface AdChunkProgressRepositoryInterface
{
    /**
     * Идемпотентно фиксирует успешное завершение чанка
     * [$dateFrom, $dateTo] для задания $jobId.
     *
     * Реализация обязана:
     *  - проверить, что $jobId принадлежит $companyId (IDOR-guard); иначе
     *    бросить {@see \DomainException};
     *  - выполнить `INSERT ... ON CONFLICT (job_id, date_from, date_to) DO NOTHING`.
     *
     * @return bool true если запись вставлена впервые, false если такой
     *              чанк уже отмечен (повторный вызов при Messenger retry).
     *
     * @throws \DomainException если $jobId не принадлежит $companyId
     */
    public function markChunkCompleted(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): bool;

    /**
     * Количество зафиксированных чанков для задания $jobId.
     *
     * Реализация обязана проверить принадлежность $jobId компании
     * $companyId перед запросом — аналогично {@see self::markChunkCompleted}.
     *
     * @throws \DomainException если $jobId не принадлежит $companyId
     */
    public function countCompletedChunks(string $jobId, string $companyId): int;
}
