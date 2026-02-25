<?php

namespace App\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\ReturnData;
use App\Marketplace\DTO\SaleData;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OzonAdapter implements MarketplaceAdapterInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const TRANSACTION_ENDPOINT = '/v3/finance/transaction/list';
    private const PAGE_SIZE = 1000;

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
            $response = $this->httpClient->request('POST', self::BASE_URL . self::TRANSACTION_ENDPOINT, [
                'headers' => $this->buildHeaders($connection),
                'json' => [
                    'filter' => [
                        'date' => [
                            'from' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d\TH:i:s.000\Z'),
                            'to' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.000\Z'),
                        ],
                        'transaction_type' => 'all',
                        'operation_type' => [],
                        'posting_number' => '',
                    ],
                    'page' => 1,
                    'page_size' => 1,
                ],
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Exception $e) {
            $this->logger->error('Ozon authentication failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить все транзакции за период (сырые данные для RawDocument).
     */
    public function fetchRawTransactions(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $connection = $this->getConnection($company);

        if (!$connection) {
            throw new \RuntimeException('Ozon connection not found');
        }

        $allOperations = [];
        $page = 1;

        do {
            $response = $this->httpClient->request('POST', self::BASE_URL . self::TRANSACTION_ENDPOINT, [
                'headers' => $this->buildHeaders($connection),
                'json' => [
                    'filter' => [
                        'date' => [
                            'from' => $fromDate->format('Y-m-d\TH:i:s.000\Z'),
                            'to' => $toDate->format('Y-m-d\TH:i:s.000\Z'),
                        ],
                        'transaction_type' => 'all',
                        'operation_type' => [],
                        'posting_number' => '',
                    ],
                    'page' => $page,
                    'page_size' => self::PAGE_SIZE,
                ],
            ]);

            $data = $response->toArray();
            $operations = $data['result']['operations'] ?? [];
            $pageCount = $data['result']['page_count'] ?? 1;

            $allOperations = array_merge($allOperations, $operations);

            $this->logger->info('Ozon transactions fetched', [
                'page' => $page,
                'page_count' => $pageCount,
                'operations_on_page' => count($operations),
            ]);

            $page++;
        } while ($page <= $pageCount);

        return $allOperations;
    }

    public function fetchSales(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        $operations = $this->fetchRawTransactions($company, $fromDate, $toDate);
        $sales = [];

        foreach ($operations as $op) {
            // Продажи: accruals_for_sale > 0, type = "orders"
            $type = $op['type'] ?? '';
            $accruals = (float)($op['accruals_for_sale'] ?? 0);

            if ($type !== 'orders' || $accruals <= 0) {
                continue;
            }

            $postingNumber = $op['posting']['posting_number'] ?? '';
            $operationDate = new \DateTimeImmutable($op['operation_date']);
            $items = $op['items'] ?? [];

            // SKU первого товара в операции
            $sku = !empty($items) ? (string)$items[0]['sku'] : '';

            $sales[] = new SaleData(
                marketplace: MarketplaceType::OZON,
                externalOrderId: $postingNumber ?: (string)$op['operation_id'],
                saleDate: $operationDate,
                marketplaceSku: $sku,
                quantity: max(1, count($items)),
                pricePerUnit: (string)$accruals,
                totalRevenue: (string)$accruals,
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
        $operations = $this->fetchRawTransactions($company, $fromDate, $toDate);
        $costs = [];

        foreach ($operations as $op) {
            $operationId = (string)($op['operation_id'] ?? '');
            $operationDate = new \DateTimeImmutable($op['operation_date']);
            $postingNumber = $op['posting']['posting_number'] ?? '';
            $items = $op['items'] ?? [];
            $sku = !empty($items) ? (string)$items[0]['sku'] : null;

            // Комиссия за продажу
            $saleCommission = (float)($op['sale_commission'] ?? 0);
            if (abs($saleCommission) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::OZON,
                    categoryCode: 'ozon_sale_commission',
                    amount: (string)abs($saleCommission),
                    costDate: $operationDate,
                    marketplaceSku: $sku,
                    description: 'Комиссия за продажу Ozon',
                    externalId: $operationId . '_commission',
                    rawData: null
                );
            }

            // Стоимость доставки
            $deliveryCharge = (float)($op['delivery_charge'] ?? 0);
            if (abs($deliveryCharge) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::OZON,
                    categoryCode: 'ozon_delivery',
                    amount: (string)abs($deliveryCharge),
                    costDate: $operationDate,
                    marketplaceSku: $sku,
                    description: 'Доставка Ozon',
                    externalId: $operationId . '_delivery',
                    rawData: null
                );
            }

            // Стоимость обратной доставки (возврат)
            $returnDeliveryCharge = (float)($op['return_delivery_charge'] ?? 0);
            if (abs($returnDeliveryCharge) > 0) {
                $costs[] = new CostData(
                    marketplace: MarketplaceType::OZON,
                    categoryCode: 'ozon_return_delivery',
                    amount: (string)abs($returnDeliveryCharge),
                    costDate: $operationDate,
                    marketplaceSku: $sku,
                    description: 'Обратная доставка Ozon',
                    externalId: $operationId . '_return_delivery',
                    rawData: null
                );
            }

            // Сервисы (хранение, обработка, подписка, и т.д.)
            $services = $op['services'] ?? [];
            foreach ($services as $idx => $service) {
                $servicePrice = (float)($service['price'] ?? 0);
                if (abs($servicePrice) <= 0) {
                    continue;
                }

                $serviceName = $service['name'] ?? 'Услуга Ozon';
                $categoryCode = $this->resolveServiceCategoryCode($serviceName);

                $costs[] = new CostData(
                    marketplace: MarketplaceType::OZON,
                    categoryCode: $categoryCode,
                    amount: (string)abs($servicePrice),
                    costDate: $operationDate,
                    marketplaceSku: $sku,
                    description: $serviceName,
                    externalId: $operationId . '_svc_' . $idx,
                    rawData: null
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
        $operations = $this->fetchRawTransactions($company, $fromDate, $toDate);
        $returns = [];

        foreach ($operations as $op) {
            $type = $op['type'] ?? '';

            // Возвраты: type = "returns"
            if ($type !== 'returns') {
                continue;
            }

            $postingNumber = $op['posting']['posting_number'] ?? '';
            $operationDate = new \DateTimeImmutable($op['operation_date']);
            $items = $op['items'] ?? [];
            $sku = !empty($items) ? (string)$items[0]['sku'] : '';

            // accruals_for_sale будет отрицательным при возврате
            $refundAmount = abs((float)($op['accruals_for_sale'] ?? 0));
            // Если нет accruals, берём amount
            if ($refundAmount <= 0) {
                $refundAmount = abs((float)($op['amount'] ?? 0));
            }

            $returnDeliveryCost = (float)($op['return_delivery_charge'] ?? 0);

            $returns[] = new ReturnData(
                marketplace: MarketplaceType::OZON,
                marketplaceSku: $sku,
                returnDate: $operationDate,
                quantity: max(1, count($items)),
                refundAmount: (string)$refundAmount,
                returnReason: $op['operation_type_name'] ?? null,
                returnLogisticsCost: abs($returnDeliveryCost) > 0 ? (string)abs($returnDeliveryCost) : null,
                externalReturnId: $postingNumber ?: (string)$op['operation_id'],
                rawData: null
            );
        }

        return $returns;
    }

    public function getMarketplaceType(): string
    {
        return MarketplaceType::OZON->value;
    }

    /**
     * Маппинг названий услуг Ozon в коды категорий затрат.
     */
    private function resolveServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        // Логистика
        if (str_contains($lower, 'логистик') || str_contains($lower, 'магистраль')
            || str_contains($lower, 'last mile') || str_contains($lower, 'last_mile')) {
            return 'ozon_logistics';
        }

        // Обработка отправления
        if (str_contains($lower, 'сборка') || str_contains($lower, 'обработк')
            || str_contains($lower, 'processing')) {
            return 'ozon_processing';
        }

        // Хранение
        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')) {
            return 'ozon_storage';
        }

        // Эквайринг
        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'оплат')) {
            return 'ozon_acquiring';
        }

        // Продвижение / реклама
        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик')) {
            return 'ozon_promotion';
        }

        // Подписка
        if (str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription')) {
            return 'ozon_subscription';
        }

        // Штраф
        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')) {
            return 'ozon_penalty';
        }

        // Возврат / компенсация
        if (str_contains($lower, 'компенсац') || str_contains($lower, 'возмещ')) {
            return 'ozon_compensation';
        }

        // Прочее
        return 'ozon_other_service';
    }

    private function buildHeaders(MarketplaceConnection $connection): array
    {
        return [
            'Client-Id' => $connection->getClientId(),
            'Api-Key' => $connection->getApiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    private function getConnection(Company $company): ?MarketplaceConnection
    {
        return $this->connectionRepository->findByMarketplace(
            $company,
            MarketplaceType::OZON
        );
    }
}
