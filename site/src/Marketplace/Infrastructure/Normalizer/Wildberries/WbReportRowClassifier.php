<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('marketplace.row_classifier')]
final readonly class WbReportRowClassifier implements RowClassifierInterface
{
    public function supports(MarketplaceType $type): bool
    {
        return $type === MarketplaceType::WILDBERRIES;
    }

    public function classify(array $rawRow): StagingRecordType
    {
        $operation = (string) ($rawRow['supplier_oper_name'] ?? $rawRow['doc_type_name'] ?? '');
        $normalized = mb_strtolower($operation);

        if (str_contains($normalized, 'продажа')) {
            return StagingRecordType::SALE;
        }

        if (str_contains($normalized, 'возврат')) {
            return StagingRecordType::RETURN;
        }

        foreach (['логистика', 'штраф', 'хранение', 'удержание', 'возмещение', 'приемка'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return StagingRecordType::COST;
            }
        }

        return StagingRecordType::OTHER;
    }
}
