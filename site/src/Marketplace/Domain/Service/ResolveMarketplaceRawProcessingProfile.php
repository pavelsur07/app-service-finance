<?php

declare(strict_types=1);

namespace App\Marketplace\Domain\Service;

use App\Marketplace\Domain\ValueObject\MarketplaceRawProcessingProfile;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;

/**
 * Доменный сервис: определяет профиль обработки raw-документа.
 *
 * Правила:
 *   - sales_report (любой маркетплейс) → daily pipeline: шаги SALES, RETURNS, COSTS
 *   - realization (Ozon monthly flow)  → вне daily pipeline, шаги не запускаются
 *   - прочие documentType             → вне daily pipeline
 *
 * Чистая бизнес-логика: без Doctrine, HTTP, сессий.
 */
final readonly class ResolveMarketplaceRawProcessingProfile
{
    private const DOCUMENT_TYPE_SALES_REPORT = 'sales_report';
    private const DOCUMENT_TYPE_REALIZATION  = 'realization';

    public function resolve(MarketplaceType $marketplace, string $documentType): MarketplaceRawProcessingProfile
    {
        if ($documentType === self::DOCUMENT_TYPE_SALES_REPORT) {
            return MarketplaceRawProcessingProfile::daily([
                PipelineStep::SALES,
                PipelineStep::RETURNS,
                PipelineStep::COSTS,
            ]);
        }

        if ($documentType === self::DOCUMENT_TYPE_REALIZATION) {
            return MarketplaceRawProcessingProfile::outsideDailyFlow(
                'realization documents are processed via the monthly realization flow, not the daily raw pipeline',
            );
        }

        return MarketplaceRawProcessingProfile::outsideDailyFlow(
            sprintf('documentType "%s" is not supported by the daily raw pipeline', $documentType),
        );
    }
}
