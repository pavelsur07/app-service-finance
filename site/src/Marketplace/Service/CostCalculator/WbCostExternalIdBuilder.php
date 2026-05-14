<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;

final readonly class WbCostExternalIdBuilder
{
    public function __construct(
        private WbSalesReportRowNormalizer $normalizer,
        private LoggerInterface $logger,
    ) {}

    public function build(array $item, string $categoryCode): ?string
    {
        $rrdId = $this->normalizer->rrdId($item);
        if ($rrdId === null) {
            $this->logger->warning('WB cost row skipped: missing rrdId/rrd_id for external_id generation.', [
                'category_code' => $categoryCode,
            ]);

            return null;
        }

        return sprintf('wb:%s:%s', $rrdId, $categoryCode);
    }
}
