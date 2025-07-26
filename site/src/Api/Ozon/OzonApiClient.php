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
        $result = [];
        $lastId = '';
        $limit = 1000;

        do {
            $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => [
                    'filter' => (object)[], // <<=== Исправление здесь!
                    'limit' => $limit,
                    'last_id' => $lastId
                ],
            ]);

            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];

            // после получения $items = $data['result']['items'] ?? [];
            file_put_contents('/tmp/ozon_debug.txt', print_r($items, true), FILE_APPEND);

            foreach ($items as $item) {
                $details = $this->http->request('POST', 'https://api-seller.ozon.ru/v2/product/info', [
                    'headers' => $this->headers($clientId, $apiKey),
                    'json' => ['product_id' => $item['product_id']],
                ])->toArray(false);

                $result[] = [
                    'sku' => $details['result']['sku'],
                    'manufacturerSku' => $details['result']['barcode'] ?? '',
                    'name' => $details['result']['name'],
                    'price' => $details['result']['price'] ?? 0,
                    'image_url' => $details['result']['images'][0] ?? null,
                    'archived' => $item['archived'] ?? false,
                ];
            }

            $lastId = $data['result']['last_id'] ?? '';
        } while (!empty($items) && !empty($lastId));

        return $result;
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
        $result = [];
        $lastId = '';
        $limit = 1000;


            $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/list', [
                'headers' => $this->headers($clientId, $apiKey),
                'json' => [
                    'filter' => (object)[], // <<=== Исправление здесь!
                    'limit' => $limit,
                    'last_id' => $lastId
                ],
            ]);

            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];

            // после получения $items = $data['result']['items'] ?? [];
            file_put_contents('/tmp/ozon_debug.txt', print_r($items, true), FILE_APPEND);

            foreach ($items as $item) {
                $details = $this->http->request('POST', 'https://api-seller.ozon.ru/v2/product/info', [
                    'headers' => $this->headers($clientId, $apiKey),
                    'json' => (object)[
                        'product_id' => (int)$item['product_id']
                    ],
                ])->toArray(false);

                $result[] = [
                    'sku' => $details['result']['sku'],
                    'manufacturerSku' => $details['result']['barcode'] ?? '',
                    'name' => $details['result']['name'],
                    'price' => $details['result']['price'] ?? 0,
                    'image_url' => $details['result']['images'][0] ?? null,
                    'archived' => $item['archived'] ?? false,
                ];
            }


        return $items;
    }
}
