<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Export;

final readonly class UnitExtendedExportRequest
{
    public function __construct(
        public string $companyId,
        public ?string $marketplace,
        public string $periodFrom,
        public string $periodTo,
    ) {
    }

    public function buildFilename(): string
    {
        $marketplace = $this->marketplace ?? 'all';

        return sprintf(
            'unit_extended_%s_%s_%s.xlsx',
            $marketplace,
            $this->periodFrom,
            $this->periodTo,
        );
    }
}
