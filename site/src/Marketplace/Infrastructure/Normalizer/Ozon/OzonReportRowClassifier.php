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
        $type = $rawRow['type'] ?? '';
        $operationType = $rawRow['operation_type'] ?? '';

        // orders → продажи
        if ($type === 'orders') {
            return StagingRecordType::SALE;
        }

        // returns:
        // ClientReturnAgentOperation = реальный возврат товара покупателем → RETURN
        // OperationItemReturn = затраты на обработку возврата (логистика) → COST
        if ($type === 'returns') {
            if ($operationType === 'ClientReturnAgentOperation') {
                return StagingRecordType::RETURN;
            }

            return StagingRecordType::COST;
        }

        // services, other, compensation → затраты
        if (in_array($type, ['services', 'other', 'compensation'], true)) {
            return StagingRecordType::COST;
        }

        return StagingRecordType::OTHER;
    }
}
