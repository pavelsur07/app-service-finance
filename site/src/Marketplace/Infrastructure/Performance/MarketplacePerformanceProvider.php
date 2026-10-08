<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Performance;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Метка provider диагностических событий M1 (ограниченный набор PerformanceRecorder::PROVIDERS).
 */
final class MarketplacePerformanceProvider
{
    public static function of(MarketplaceType $marketplace): string
    {
        return match ($marketplace) {
            MarketplaceType::OZON => 'ozon',
            MarketplaceType::WILDBERRIES => 'wb',
            default => 'none',
        };
    }
}
