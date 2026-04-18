<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

/**
 * Контракт ledger-репозитория {@see \App\MarketplaceAds\Entity\AdChunkProgress}.
 *
 * Вынесен в интерфейс, чтобы оставить concrete-класс `final` (CLAUDE.md) и
 * мокать из unit-тестов хендлеров без подклассов.
 */
interface AdChunkProgressRepositoryInterface
{
    /**
     * Идемпотентно фиксирует факт выгрузки чанка.
     *
     * Реализовано через `INSERT ... ON CONFLICT DO NOTHING`:
     *  - первый вызов для (jobId, dateFrom, dateTo) → строка вставлена → true;
     *  - любой повтор (retry оркестратора, retry Messenger'а после сбоя
     *    пост-flush, кросс-job re-fetch того же периода) → 0 затронутых
     *    строк → false.
     *
     * Возврат — единственный надёжный сигнал «этот чанк учтён впервые»,
     * на основании которого FetchOzonAdStatisticsHandler инкрементит
     * chunks_completed. created/updated счёт документов для этой цели
     * недостаточен: AdRawDocument может быть existing из-за CLI-preload
     * или нового jobId на уже загруженный период.
     *
     * @return bool true если запись создана (первая фиксация чанка),
     *              false если уже существовала (retry любого рода)
     */
    public function tryMarkCompleted(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): bool;
}
