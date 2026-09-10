<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Contract;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;

interface RowClassifierInterface
{
    /**
     * @param MarketplaceRawFormat|null $format поколение API документа; null
     *                                          означает «формат не определён» и
     *                                          сохраняет прежнее поведение
     */
    public function supports(MarketplaceType $type, ?MarketplaceRawFormat $format = null): bool;

    public function classify(array $rawRow): StagingRecordType;
}
