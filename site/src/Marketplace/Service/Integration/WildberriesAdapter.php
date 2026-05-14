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
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WildberriesAdapter implements MarketplaceAdapterInterface
{
    private const BASE_URL = 'https://statistics-api.wildberries.ru';
    private const WB_HEADER_RETRY = 'x-ratelimit-retry';
    private const WB_HEADER_RESET = 'x-ratelimit-reset';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly LoggerInterface $logger,
        private readonly WbSalesReportRowNormalizer $normalizer,
    ) {
    }

    public function authenticate(Company $company): bool
    {
        $connection = $this->getConnection($company);

        if (!$connection) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/api/v5/supplier/reportDetailByPeriod', [
                'headers' => [
                    'Authorization' => $connection->getApiKey(),
                ],
                'query' => [
                    'dateFrom' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d'),
                    'dateTo' => (new \DateTimeImmutable())->format('Y-m-d'),
                    'limit' => 1,
                    'rrdid' => 0,
                ],
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Exception $e) {
            return false;
        }
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

        $response = $this->httpClient->request('GET', self::BASE_URL.'/api/v5/supplier/reportDetailByPeriod', [
            'headers' => [
                'Authorization' => $connection->getApiKey(),
            ],
            'query' => [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => 100000,
                'rrdid' => 0,
                'period' => 'daily',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new MarketplaceTemporaryApiException('WB API transport error.', $statusCode, '', $dateFrom, $dateTo, $e);
        }
        $excerpt = $this->createSafeExcerpt($body);

        if (204 === $statusCode) {
            $this->logger->info('WB API no data', [
                'status_code' => $statusCode,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return [];
        }

        if (429 === $statusCode) {
            $retryAfter = $this->extractRetryAfter($headers);
            $this->logger->warning('WB API rate limit', [
                'status_code' => $statusCode,
                'retry_after' => $retryAfter,
                'body_excerpt' => $excerpt,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $retryAfter);
        }

        if (401 === $statusCode || 403 === $statusCode) {
            $this->logger->warning('WB API auth error', [
                'status_code' => $statusCode,
                'body_excerpt' => $excerpt,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            throw new MarketplaceAuthException('WB API authentication failed.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        if ($statusCode >= 500 && $statusCode <= 599) {
            $this->logger->warning('WB API temporary error', [
                'status_code' => $statusCode,
                'body_excerpt' => $excerpt,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        if (200 !== $statusCode) {
            throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        if ('' === trim($body)) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MarketplaceInvalidApiResponseException('WB API returned invalid JSON.', $statusCode, $excerpt, $dateFrom, $dateTo, $e);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new MarketplaceInvalidApiResponseException('WB API JSON must be a list.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new MarketplaceInvalidApiResponseException('WB API JSON list items must be objects.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
        }

        if (count($decoded) > 100_000) {
            $this->logger->warning('WB payload too large', [
                'count' => count($decoded),
            ]);
        }

        return $decoded;
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

        $response = $this->httpClient->request('GET', self::BASE_URL.'/api/v5/supplier/reportDetailByPeriod', [
            'headers' => [
                'Authorization' => $connection->getApiKey(),
            ],
            'query' => [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => 1,
                'rrdid' => 0,
                'period' => 'daily',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new MarketplaceTemporaryApiException('WB API transport error.', $statusCode, '', $dateFrom, $dateTo, $e);
        }
        $excerpt = $this->createSafeExcerpt($body);

        if (204 === $statusCode) {
            $this->logger->debug('WB API no data', [
                'status_code' => $statusCode,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return false;
        }

        if (429 === $statusCode) {
            $retryAfter = $this->extractRetryAfter($headers);
            throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $retryAfter);
        }
        if (401 === $statusCode || 403 === $statusCode) {
            throw new MarketplaceAuthException('WB API authentication failed.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if (200 !== $statusCode) {
            throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if ('' === trim($body)) {
            return false;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MarketplaceInvalidApiResponseException('WB API returned invalid JSON.', $statusCode, $excerpt, $dateFrom, $dateTo, $e);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new MarketplaceInvalidApiResponseException('WB API JSON must be a list.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new MarketplaceInvalidApiResponseException('WB API JSON list items must be objects.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
        }

        return [] !== $decoded;
    }

    /**
     * Legacy DTO API. Do not use for WB financial pipeline. Use raw report processors instead.
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

            $commissionAmount = abs($this->normalizer->fullMarketplaceCommission($item));
            if ($commissionAmount > 0) {
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
        return 'wildberries::reportDetailByPeriod';
    }


    private function extractRetryAfter(array $headers): ?int
    {
        $retryAfter = $this->extractHeaderInt($headers, self::WB_HEADER_RETRY);
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $reset = $this->extractHeaderInt($headers, self::WB_HEADER_RESET);
        if ($reset !== null) {
            $now = (new \DateTimeImmutable())->getTimestamp();

            return $reset > $now
                ? max(0, $reset - $now)
                : $reset;
        }

        return $this->extractHeaderInt($headers, 'retry-after');
    }

    private function extractHeaderInt(array $headers, string $headerName): ?int
    {
        if (!isset($headers[$headerName][0])) {
            return null;
        }

        $value = trim((string) $headers[$headerName][0]);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function createSafeExcerpt(string $body): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($body)) ?? '';

        return mb_substr($normalized, 0, 500);
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
