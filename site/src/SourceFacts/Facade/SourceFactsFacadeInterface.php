<?php

declare(strict_types=1);

namespace App\SourceFacts\Facade;

use App\SourceFacts\Application\DTO\SourceFactRecord;
use App\SourceFacts\Application\DTO\SourcePeriod;

/**
 * Чтение принятых фактов — для переводчиков каналов. Только чтение, транзакции не нужны.
 */
interface SourceFactsFacadeInterface
{
    /**
     * Периоды ресурса с текущей ревизией: все компании (переводчик, гейт) или одна (экран качества).
     *
     * @companyScopeExempt без `$companyId` — переводчик и гейт обходят все компании (cron)
     *
     * @return list<SourcePeriod>
     */
    public function periods(string $resourceType, ?string $companyId = null): array;

    /**
     * Действующие записи текущей ревизии периода, по `source_key`.
     *
     * @return list<SourceFactRecord>
     */
    public function activeFacts(string $companyId, string $connectionRef, string $resourceType, string $periodKey): array;
}
