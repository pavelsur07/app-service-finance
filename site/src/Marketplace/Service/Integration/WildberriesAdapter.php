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
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WildberriesAdapter implements MarketplaceAdapterInterface
{
    private const BASE_URL = 'https://statistics-api.wildberries.ru';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly LoggerInterface $logger,
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
        $body = $response->getContent(false);
        $excerpt = $this->createSafeExcerpt($body);

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

        return $decoded;
    }

    public function fetchSales(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $sales = [];

        foreach ($data as $item) {
            if (!isset($item['doc_type_name']) || 'Продажа' !== $item['doc_type_name']) {
                continue;
            }

            $retailAmount = (float) ($item['retail_amount'] ?? 0);
            if ($retailAmount <= 0) {
                continue;
            }

            $sales[] = new SaleData(
                marketplace: MarketplaceType::WILDBERRIES,
                externalOrderId: (string) $item['realizationreport_id'],
                saleDate: new \DateTimeImmutable($item['rr_dt']),
                marketplaceSku: $item['sa_name'],
                quantity: abs((int) $item['quantity']),
                pricePerUnit: (string) $item['retail_price'],
                totalRevenue: (string) abs($retailAmount),
                rawData: null
            );
        }

        return $sales;
    }

    public function fetchCosts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $costs = [];

        foreach ($data as $item) {
            $realizationId = (string) $item['realizationreport_id'];
            $saName = $item['sa_name'];
            $rrDt = new \DateTimeImmutable($item['rr_dt']);
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            $tsName = isset($item['ts_name']) ? (string) $item['ts_name'] : null;
            $barcode = isset($item['barcode']) ? (string) $item['barcode'] : null;

            if (isset($item['commission_percent']) && abs((float) $item['commission_percent']) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::WILDBERRIES,
                    categoryCode: 'wb_commission',
                    amount: (string) abs((float) $item['commission_percent']),
                    costDate: $rrDt,
                    marketplaceSku: $saName,
                    description: 'Комиссия Wildberries',
                    externalId: $realizationId.'_commission',
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
                    externalId: $realizationId.'_logistics',
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
                    externalId: $realizationId.'_return_logistics',
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
                    externalId: $realizationId.'_storage',
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
                    externalId: $realizationId.'_acceptance',
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
                    externalId: $realizationId.'_deduction',
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
                    externalId: $realizationId.'_penalty',
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
                    externalId: $realizationId.'_additional_payment',
                    nmId: $nmId,
                    tsName: $tsName,
                    barcode: $barcode,
                );
            }
        }

        return $costs;
    }

    public function fetchReturns(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $data = $this->fetchRawReport($company, $fromDate, $toDate);
        $returns = [];

        foreach ($data as $item) {
            if (!isset($item['doc_type_name']) || 'Возврат' !== $item['doc_type_name']) {
                continue;
            }

            $returns[] = new ReturnData(
                marketplace: MarketplaceType::WILDBERRIES,
                marketplaceSku: $item['sa_name'],
                returnDate: new \DateTimeImmutable($item['rr_dt']),
                quantity: abs((int) $item['quantity']),
                refundAmount: (string) abs((float) ($item['retail_amount'] ?? 0)),
                returnReason: $item['supplier_oper_name'] ?? null,
                returnLogisticsCost: isset($item['return_amount']) ? (string) abs((float) $item['return_amount']) : null,
                externalReturnId: (string) $item['realizationreport_id'],
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
        $values = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
        if (!is_array($values) || [] === $values) {
            return null;
        }

        $raw = trim((string) $values[0]);

        return ctype_digit($raw) ? (int) $raw : null;
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
