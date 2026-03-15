<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Получает данные товаров Ozon по списку SKU.
 *
 * API: POST /v3/product/info/list
 * Docs: https://docs.ozon.ru/api/seller/#operation/ProductAPI_GetProductInfoListV3
 *
 * Формат запроса: {"sku": [308520421, ...], "offer_id": [], "product_id": []}
 * Формат ответа:  items[].sku + items[].barcodes[] + items[].offer_id
 *
 * Лимит: 1000 SKU за запрос.
 */
final class OzonProductBarcodeFetcher
{
    private const BASE_URL   = 'https://api-seller.ozon.ru';
    private const ENDPOINT   = '/v3/product/info/list';
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly HttpClientInterface         $httpClient,
        private readonly MarketplaceCredentialsQuery $credentialsQuery,
        private readonly LoggerInterface             $logger,
    ) {
    }

    /**
     * Получить баркоды и артикул продавца для списка SKU.
     *
     * @param  string[] $skus  marketplace_sku (финансовый SKU Ozon)
     * @return array<string, array{barcodes: string[], offer_id: string|null}>
     */
    public function fetchProductDataBySkus(string $companyId, array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::OZON);

        if ($credentials === null || $credentials['api_key'] === '' || $credentials['client_id'] === null) {
            throw new \RuntimeException('Ozon API credentials not found for company: ' . $companyId);
        }

        $headers = [
            'Client-Id'    => $credentials['client_id'],
            'Api-Key'      => $credentials['api_key'],
            'Content-Type' => 'application/json',
        ];

        $result  = [];
        $batches = array_chunk($skus, self::BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('[OzonBarcode] Fetching batch', [
                'company_id' => $companyId,
                'batch'      => $batchIndex + 1,
                'total'      => count($batches),
                'skus_count' => count($batch),
            ]);

            $response = $this->httpClient->request('POST', self::BASE_URL . self::ENDPOINT, [
                'headers' => $headers,
                'json'    => [
                    'sku'        => array_map('intval', $batch),
                    'offer_id'   => [],
                    'product_id' => [],
                ],
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf(
                    'Ozon product info API returned HTTP %d',
                    $statusCode,
                ));
            }

            $data  = $response->toArray();
            $items = $data['items'] ?? [];

            foreach ($items as $item) {
                $sku      = (string) ($item['sku'] ?? '');
                $barcodes = array_values(
                    array_filter(array_map('strval', $item['barcodes'] ?? []))
                );
                $offerId  = isset($item['offer_id']) && $item['offer_id'] !== ''
                    ? (string) $item['offer_id']
                    : null;

                if ($sku === '') {
                    continue;
                }

                $result[$sku] = [
                    'barcodes' => $barcodes,
                    'offer_id' => $offerId,
                ];
            }

            $this->logger->info('[OzonBarcode] Batch fetched', [
                'company_id'     => $companyId,
                'batch'          => $batchIndex + 1,
                'items_returned' => count($items),
                'with_barcodes'  => count(array_filter($result, fn($d) => !empty($d['barcodes']))),
            ]);
        }

        return $result;
    }
}
