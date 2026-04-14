<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.marketplace.raw_processor')]
interface MarketplaceRawProcessorInterface
{
    /**
     * Контракт не меняем: существующие raw processors остаются совместимыми.
     * Daily pipeline оркестрирует уже имеющиеся процессоры без изменения их runtime-поведения.
     */
    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool;

    public function process(string $companyId, string $rawDocId): int;

    /**
     * Обработка батча из daily pipeline.
     * rawDocId проставляется на создаваемые entity (raw_document_id)
     * и используется для очистки legacy-записей перед вставкой.
     *
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void;
}
