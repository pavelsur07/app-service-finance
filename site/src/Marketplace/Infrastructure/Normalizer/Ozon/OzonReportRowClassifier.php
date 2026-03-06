<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('marketplace.row_classifier')]
final readonly class OzonReportRowClassifier implements RowClassifierInterface
{
    public function supports(MarketplaceType $type): bool
    {
        return $type === MarketplaceType::OZON;
    }

    public function classify(array $rawRow): StagingRecordType
    {
        $operationType = (string) ($rawRow['operation_type'] ?? '');

        if (in_array($operationType, ['OperationItemReturn', 'Return'], true)) {
            return StagingRecordType::RETURN;
        }

        if (in_array($operationType, ['OperationItemDelivered', 'ClientReturnStorno'], true)) {
            return StagingRecordType::SALE;
        }

        foreach (['Service', 'Logistics', 'Penalty', 'Fulfillment'] as $keyword) {
            if (str_contains($operationType, $keyword)) {
                return StagingRecordType::COST;
            }
        }

        return StagingRecordType::OTHER;
    }
}
