<?php

namespace App\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\ReturnData;
use App\Marketplace\DTO\SaleData;

interface MarketplaceAdapterInterface
{
    /**
     * Проверка подключения к API маркетплейса.
     */
    public function authenticate(Company $company): bool;

    /**
     * Получить продажи за период.
     *
     * @return SaleData[]
     */
    public function fetchSales(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array;

    /**
     * Получить затраты за период.
     *
     * @return CostData[]
     */
    public function fetchCosts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array;

    /**
     * Получить возвраты за период.
     *
     * @return ReturnData[]
     */
    public function fetchReturns(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array;

    /**
     * Получить тип маркетплейса.
     */
    public function getMarketplaceType(): string;
}
