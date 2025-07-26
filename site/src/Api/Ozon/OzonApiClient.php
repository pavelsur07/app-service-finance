<?php

namespace App\Api\Ozon;

use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class OzonApiClient
{
    public function __construct(
        private string              $clientId,
        private string              $apiKey,
        private HttpClientInterface $http
    ) {}

    private function headers(): array
    {
        return [
            'Client-Id' => $this->clientId,
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function getAllProducts(): array
    {
        $result = [];
        $lastId = '';
        $limit = 1000;

        do {
            $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v3/product/list', [
                'headers' => $this->headers(),
                'json' => ['limit' => $limit, 'last_id' => $lastId],
            ]);

            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];

            foreach ($items as $item) {
                $details = $this->http->request('POST', 'https://api-seller.ozon.ru/v2/product/info', [
                    'headers' => $this->headers(),
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
}
