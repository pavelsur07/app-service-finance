<?php

namespace App\Marketplace\Ozon\Adapter;

use App\Entity\Company;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OzonApiClient
{
    private const BASE_URL = 'https://api-seller.ozon.ru';

    public function __construct(
        private HttpClientInterface $http,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private function headers(string $clientId, string $apiKey): array
    {
        return [
            'Client-Id' => $clientId,
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    private function companyHeaders(Company $company): array
    {
        return [
            'Client-Id' => $company->getOzonSellerId(),
            'Api-Key' => $company->getOzonApiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function request(Company $company, string $path, array $body): array
    {
        $headers = $this->companyHeaders($company);
        $delay = 1;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $response = $this->http->request('POST', self::BASE_URL.$path, [
                    'headers' => $headers,
                    'json' => $body,
                ]);
                $status = $response->getStatusCode();
                $reqId = $response->getHeaders(false)['x-request-id'][0] ?? null;
                if ($reqId) {
                    $this->logger->info('Ozon request', ['path' => $path, 'x-request-id' => $reqId]);
                }
                if (in_array($status, [429, 503], true)) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }

                return $response->toArray(false);
            } catch (TransportExceptionInterface $e) {
                sleep($delay);
                $delay *= 2;
                if (4 === $attempt) {
                    throw $e;
                }
            }
        }

        return [];
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
                    'filter' => (object) [],  // обязателен!
                    'limit' => $limit,
                    'last_id' => $lastId,
                ],
            ]);
            $data = $response->toArray(false);
            $items = $data['result']['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = (int) $item['product_id'];
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
                    'id' => $product['id'] ?? '',
                    'offer_id' => $product['offer_id'] ?? '',
                    'sku' => isset($product['sources'][0]['sku']) ? (string) $product['sources'][0]['sku'] : '',
                    'name' => $product['name'] ?? '',
                    'price' => isset($product['price']) ? (float) $product['price'] : 0.0,
                    'barcode' => $product['barcodes'][0] ?? '',
                    'image_url' => $product['images'][0] ?? null,
                    'archived' => $product['is_archived'] ?? false,
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

    public function test(string $clientId, string $apiKey, \DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v2/finance/realization', [
            'headers' => $this->headers($clientId, $apiKey),
            'json' => [
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->format('Y-m-d'),
                'language' => 'DEFAULT',
            ],
        ]);

        return $response->getContent();
    }

    public function createRealizationReport(string $clientId, string $apiKey, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $response = $this->http->request('POST', 'https://api-seller.ozon.ru/v1/report/realization/create', [
            'headers' => $this->headers($clientId, $apiKey),
            'json' => [
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->format('Y-m-d'),
                'language' => 'DEFAULT',
            ],
        ]);

        $data = $response->toArray(false);

        return (string) ($data['result']['report_id'] ?? '');
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

    public function getFbsPostingsList(Company $company, \DateTimeImmutable $since, \DateTimeImmutable $to, array|string|null $status = null, int $limit = 1000, int $offset = 0, array $withFlags = []): array
    {
        $filter = [
            'since' => $since->format(\DATE_ATOM),
            'to' => $to->format(\DATE_ATOM),
        ];
        if (null !== $status) {
            $filter['status'] = $status;
        }
        $body = [
            'dir' => 'asc',
            'filter' => $filter,
            'limit' => $limit,
            'offset' => $offset,
        ];
        $with = [];
        foreach (['analytics_data', 'financial_data', 'barcodes'] as $flag) {
            if (isset($withFlags[$flag]) && $withFlags[$flag]) {
                $with[$flag] = true;
            }
        }
        if ($with) {
            $body['with'] = $with;
        }

        return $this->request($company, '/v3/posting/fbs/list', $body);
    }

    public function getFbsPosting(Company $company, string $postingNumber, array $withFlags = []): array
    {
        $body = ['posting_number' => $postingNumber];
        $with = [];
        foreach (['analytics_data', 'financial_data', 'barcodes', 'legal_info', 'product_exemplars', 'related_postings', 'translit'] as $flag) {
            if (isset($withFlags[$flag]) && $withFlags[$flag]) {
                $with[$flag] = true;
            }
        }
        if ($with) {
            $body['with'] = $with;
        }

        return $this->request($company, '/v3/posting/fbs/get', $body);
    }

    public function getFbsUnfulfilledList(Company $company, int $limit = 1000, int $offset = 0, array $withFlags = []): array
    {
        $body = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        $with = [];
        foreach (['analytics_data', 'financial_data', 'barcodes'] as $flag) {
            if (isset($withFlags[$flag]) && $withFlags[$flag]) {
                $with[$flag] = true;
            }
        }
        if ($with) {
            $body['with'] = $with;
        }

        return $this->request($company, '/v3/posting/fbs/unfulfilled/list', $body);
    }

    public function getFboPostingsList(Company $company, \DateTimeImmutable $since, \DateTimeImmutable $to, int $limit = 1000, int $offset = 0): array
    {
        $body = [
            'filter' => [
                'since' => $since->format(\DATE_ATOM),
                'to' => $to->format(\DATE_ATOM),
            ],
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->request($company, '/v2/posting/fbo/list', $body);
    }

    public function getFboPosting(Company $company, string $postingNumber): array
    {
        $body = ['posting_number' => $postingNumber];

        return $this->request($company, '/v2/posting/fbo/get', $body);
    }
}
