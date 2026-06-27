<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

final class OzonPerformanceCampaignNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly string $campaignId,
        public readonly string $endpoint,
        public readonly string $responseBody,
    ) {
        parent::__construct(sprintf('Ozon Performance campaign "%s" was not found for %s.', $campaignId, $endpoint));
    }
}
