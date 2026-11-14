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
     * Все периоды ресурса по всем компаниям с текущей ревизией.
     *
     * @return list<SourcePeriod>
     */
    public function periods(string $resourceType): array;

    /**
     * Действующие записи текущей ревизии периода, по `source_key`.
     *
     * @return list<SourceFactRecord>
     */
    public function activeFacts(string $companyId, string $connectionRef, string $resourceType, string $periodKey): array;
}
