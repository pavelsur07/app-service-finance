<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Application\ProcessOzonReturnsAction;

final class OzonReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly ProcessOzonReturnsAction $action)
    {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::RETURN
            && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'returns';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return ($this->action)($companyId, $rawDocId);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        // TODO: Будет реализовано в PR 5 (Перевод Costs и Returns на новую архитектуру).
        // Пока оставляем пустым, чтобы Демультиплексор мог безопасно маршрутизировать эти строки без падения.
    }
}
