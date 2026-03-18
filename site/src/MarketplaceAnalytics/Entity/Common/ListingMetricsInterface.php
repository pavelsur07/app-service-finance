<?php

namespace App\MarketplaceAnalytics\Entity\Common;

interface ListingMetricsInterface
{
    public function getListingId(): string;
    public function getMarketplaceType(): string; // 'wildberries', 'ozon'
    public function getMetricDate(): \DateTimeImmutable;

    // Универсальные метрики (есть у всех МП)
    public function getViews(): ?int;
    public function getOrders(): int;
    public function getSales(): int;
    public function getReturns(): int;
    public function getRevenue(): string;
    public function getPurchaseRate(): ?float;

    // Метаданные
    public function getDataQuality(): string; // 'exact', 'calculated'
}
