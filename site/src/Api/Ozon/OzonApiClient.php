<?php

namespace App\Api\Ozon;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OzonApiClient
{
    public function __construct(
        private HttpClientInterface $http
    ) {}

    private function headers(string $clientId, string $apiKey): array
    {
        return [
            'Client-Id' => $clientId,
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function getAllProducts(string $clientId, string $apiKey): array
    {
        $lastId = '';
        $limit = 1000;
        $productIds = [];

        // 1. Получаем все product_id через /v3/product/list
        do {
            $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => [
                    'filter' => (object)[],  // обязателен!
                    'limit' => $limit,
                    'last_id' => $lastId,
                ],
            ]);
            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = (int)$item['product_id'];
                }
            }
            $lastId = $data['result']['last_id'] ?? '';
        } while (!empty($items) && !empty($lastId));

        if (!$productIds) {
            return [];
        }

        // 2. Пакетно получаем подробную информацию
        $allProducts = [];
        $chunks = array_chunk($productIds, 100);

        foreach ($chunks as $chunk) {
            $infoResponse = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/info/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => ['product_id' => $chunk],
            ]);
            $infoData = $infoResponse->toArray(false);

            foreach (($infoData['items'] ?? []) as $product) {
                $allProducts[] = [
                    'id'           => $product['id'] ?? '',
                    'offer_id'          => $product['offer_id'] ?? '',
                    'sku'       => isset($product['sources'][0]['sku']) ? (string)$product['sources'][0]['sku'] : '',
                    'name'         => $product['name'] ?? '',
                    'price'        => isset($product['price']) ? (float) $product['price'] : 0.0,
                    'barcode'      => $product['barcodes'][0] ?? '',
                    'image_url'    => $product['images'][0] ?? null,
                    'archived'     => $product['is_archived'] ?? false,
                ];
            }
        }

        return $allProducts;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getAllProductsTest(string $clientId, string $apiKey): array
    {
        $lastId = '';
        $limit = 1000;
        $productIds = [];

        // 1. Получаем все product_id через /v3/product/list
        do {
            $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => [
                    'filter' => (object)[],  // обязателен!
                    'limit' => $limit,
                    'last_id' => $lastId,
                ],
            ]);
            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = (int)$item['product_id'];
                }
            }
            $lastId = $data['result']['last_id'] ?? '';
        } while (!empty($items) && !empty($lastId));

        if (!$productIds) {
            return [];
        }

        // 2. Пакетно получаем подробную информацию
        $allProducts = [];
        $chunks = array_chunk($productIds, 100);

        foreach ($chunks as $chunk) {
            $infoResponse = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/info/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => ['product_id' => $chunk],
            ]);
            $infoData = $infoResponse->toArray(false);

            foreach (($infoData['items'] ?? []) as $product) {
                $allProducts[] = [
                    'id'           => $product['id'] ?? '',
                    'offer_id'     => $product['offer_id'] ?? '',
                    'sku'       => isset($product['sources'][0]['sku']) ? (string)$product['sources'][0]['sku'] : '',
                    'name'         => $product['name'] ?? '',
                    'price'        => isset($product['price']) ? (float) $product['price'] : 0.0,
                    'barcode'      => $product['barcodes'][0] ?? '',
                    'image_url'    => $product['images'][0] ?? null,
                    'archived'     => $product['is_archived'] ?? false,
                ];
            }
        }

        return $allProducts;
    }

    public function getStocks(string $clientId, string $apiKey): array
    {
        $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v1/product/info/stocks', [
            'headers' => $this->headers($clientId, $apiKey),
            'json' => [],
        ]);

        try {
            $data = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            return [];
        }

        return $data['result'] ?? [];
    }

    public function createSalesReport(string $clientId, string $apiKey, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v1/analytics/create', [
            'headers' => $this->headers($clientId, $apiKey),
            'json' => [
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->format('Y-m-d'),
                'metrics' => ['sku', 'sales'],
                'dimension' => ['sku'],
                'with_detail' => true,
            ],
        ]);

        $data = $response->toArray(false);

        return (string) ($data['result']['task_id'] ?? '');
    }

    public function downloadSalesReport(string $clientId, string $apiKey, string $taskId): string
    {
        $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v1/analytics/report/info', [
            'headers' => $this->headers($clientId, $apiKey),
            'json' => ['task_id' => $taskId],
        ]);
        $data = $response->toArray(false);

        $url = $data['result']['file'] ?? null;
        if (!$url) {
            return '';
        }

        return $this->http->request('GET', $url)->getContent();
    }
}
