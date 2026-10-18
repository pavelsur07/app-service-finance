<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\DTO\CategoryGroupView;
use App\Ingestion\Application\DTO\OzonAccrualDetailsImportResult;
use App\Ingestion\Application\DTO\OzonAccrualTypeDisputeView;
use App\Ingestion\Application\DTO\OzonAccrualTypeQueueItem;

/**
 * Админский контракт справочника типов начислений Ozon: очередь новых типов,
 * подтверждение, споры по группам и импорт отчёта кабинета «Детализация
 * начислений». Решения глобальные — действуют для всех компаний.
 */
interface OzonAccrualTypesAdminFacadeInterface
{
    /**
     * @return list<OzonAccrualTypeQueueItem>
     */
    public function typeQueue(int $limit = 100): array;

    /**
     * @return list<OzonAccrualTypeDisputeView>
     */
    public function groupDisputes(): array;

    /**
     * @return list<CategoryGroupView>
     */
    public function ozonGroups(): array;

    /**
     * @return int сколько внешних категорий получили маппинг
     *
     * @throws \App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException
     * @throws \App\Ingestion\Exception\UnknownCategoryGroupException
     */
    public function confirmType(int $typeId, string $groupCode, string $label, string $actorUserId): int;

    /**
     * @throws \App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException
     */
    public function resolveGroupDispute(int $typeId, bool $accept, string $actorUserId): void;

    /**
     * @throws \App\Ingestion\Exception\OzonAccrualDetailsFileException
     */
    public function importDetailsReport(string $companyId, string $path, bool $dryRun, string $actorUserId): OzonAccrualDetailsImportResult;
}
