<?php

declare(strict_types=1);

namespace App\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\ReturnData;
use App\Marketplace\DTO\SaleData;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WildberriesAdapter implements MarketplaceAdapterInterface
{
    public const FINANCE_API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly LoggerInterface $logger,
        private readonly WbSalesReportRowNormalizer $normalizer,
        private readonly WbFinanceSalesReportClient $salesReportClient,
    ) {
    }

    public function authenticate(Company $company): bool
    {
        $connection = $this->getConnection($company);

        if (!$connection) {
            return false;
        }

        return $this->salesReportClient->probeAccess($connection->getApiKey());
    }

    public function fetchRawReport(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $connection = $this->getConnection($company);

        if (!$connection) {
            throw new \RuntimeException('Wildberries connection not found');
        }

        $dateFrom = $fromDate->format('Y-m-d');
        $dateTo = $toDate->format('Y-m-d');

        return $this->salesReportClient->fetchDetailed($connection->getApiKey(), $dateFrom, $dateTo);
    }

    public function hasRawReportData(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): bool {
        $connection = $this->getConnection($company);

        if (!$connection) {
            throw new \RuntimeException('Wildberries connection not found');
        }

        $dateFrom = $fromDate->format('Y-m-d');
        $dateTo = $toDate->format('Y-m-d');

        return $this->salesReportClient->hasAnyData($connection->getApiKey(), $dateFrom, $dateTo);
    }

    /**
     * Legacy DTO API. Do not use for WB financial pipeline. Use raw report processors instead.
     *
     * @deprecated
     */
    public function fetchSales(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $sales = [];

        foreach ($data as $item) {
            if (!$this->normalizer->isSale($item)) {
                continue;
            }

            $retailPriceWithDisc = $this->normalizer->retailPriceWithDisc($item);
            if ($retailPriceWithDisc <= 0) {
                continue;
            }

            $quantity = abs($this->normalizer->quantity($item));
            $externalOrderId = $this->normalizer->rrdId($item) ?? (string) ($item['realizationreport_id'] ?? '');
            if ($externalOrderId === '') {
                continue;
            }

            $sales[] = new SaleData(
                marketplace: MarketplaceType::WILDBERRIES,
                externalOrderId: $externalOrderId,
                saleDate: $this->normalizer->operationDate($item),
                marketplaceSku: $this->normalizer->vendorCode($item),
                quantity: $quantity,
                pricePerUnit: (string) ($quantity > 0 ? $retailPriceWithDisc / $quantity : 0.0),
                totalRevenue: (string) $retailPriceWithDisc,
                rawData: null
            );
        }

        return $sales;
    }

    /**
     * Legacy DTO API. Do not use for WB financial pipeline. Use raw report processors instead.
     *
     * @deprecated
     */
    public function fetchCosts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $costs = [];

        foreach ($data as $item) {
            $rrdId = $this->normalizer->rrdId($item) ?? (string) ($item['realizationreport_id'] ?? '');
            if ($rrdId === '') {
                continue;
            }

            $saName = $this->normalizer->vendorCode($item);
            $rrDt = $this->normalizer->reportDate($item);
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            $tsName = isset($item['ts_name']) ? (string) $item['ts_name'] : null;
            $barcode = isset($item['barcode']) ? (string) $item['barcode'] : null;

            $isReturn = $this->normalizer->isReturn($item);
            $commissionAmount = abs($this->normalizer->fullMarketplaceCommission($item));
            if (!$isReturn && $commissionAmount > 0) {
                // Legacy CostData does not support operation_type/STORNO.
                // Return commission/acquiring is handled by WB raw cost processors only.
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_commission',
                    amount: (string) $commissionAmount,
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Комиссия Wildberries',
                    externalId: 'wb:'.$rrdId.':wb_commission',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['delivery_rub']) && abs((float) $item['delivery_rub']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_logistics',
                    amount: (string) abs((float) $item['delivery_rub']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Логистика WB',
                    externalId: 'wb:'.$rrdId.':wb_logistics',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['return_amount']) && abs((float) $item['return_amount']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_return_logistics',
                    amount: (string) abs((float) $item['return_amount']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Логистика возврата WB',
                    externalId: 'wb:'.$rrdId.':wb_return_logistics',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['storage_fee']) && abs((float) $item['storage_fee']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_storage',
                    amount: (string) abs((float) $item['storage_fee']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Хранение на складе WB',
                    externalId: 'wb:'.$rrdId.':wb_storage',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['acceptance']) && abs((float) $item['acceptance']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_acceptance',
                    amount: (string) abs((float) $item['acceptance']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Платная приёмка WB',
                    externalId: 'wb:'.$rrdId.':wb_acceptance',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['deduction']) && abs((float) $item['deduction']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_deduction',
                    amount: (string) abs((float) $item['deduction']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Прочие удержания WB',
                    externalId: 'wb:'.$rrdId.':wb_deduction',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['penalty']) && abs((float) $item['penalty']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_penalty',
                    amount: (string) abs((float) $item['penalty']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Штрафы WB',
                    externalId: 'wb:'.$rrdId.':wb_penalty',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }

            if (isset($item['additional_payment']) && abs((float) $item['additional_payment']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_additional_payment',
                    amount: (string) abs((float) $item['additional_payment']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Доплаты WB',
                    externalId: 'wb:'.$rrdId.':wb_additional_payment',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }
        }

        return $costs;
    }

    /**
     * Legacy DTO API. Do not use for WB financial pipeline. Use raw report processors instead.
     *
     * @deprecated
     */
    public function fetchReturns(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $returns = [];

        foreach ($data as $item) {
            if (!$this->normalizer->isReturn($item)) {
                continue;
            }

            $returns[] = new ReturnData(
                marketplace: MarketplaceType::WILDBERRIES,
                marketplaceSku: $this->normalizer->vendorCode($item),
                returnDate: $this->normalizer->reportDate($item),
                quantity: abs($this->normalizer->quantity($item)),
                refundAmount: (string) abs($this->normalizer->retailPriceWithDisc($item)),
                returnReason: $item['supplier_oper_name'] ?? null,
                returnLogisticsCost: isset($item['return_amount']) ? (string) abs((float) $item['return_amount']) : null,
                externalReturnId: $this->normalizer->rrdId($item) ?? (string) ($item['realizationreport_id'] ?? ''),
                nmId: isset($item['nm_id']) ? (string) $item['nm_id'] : null,
                tsName: isset($item['ts_name']) ? (string) $item['ts_name'] : null,
                barcode: isset($item['barcode']) ? (string) $item['barcode'] : null,
            );
        }

        return $returns;
    }

    public function getMarketplaceType(): string
    {
        return MarketplaceType::WILDBERRIES->value;
    }

    public function getApiEndpointName(): string
    {
        return self::FINANCE_API_ENDPOINT;
    }


    private function getConnection(Company $company): ?MarketplaceConnection
    {
        return $this->connectionRepository->findByMarketplace(
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
    }
}

