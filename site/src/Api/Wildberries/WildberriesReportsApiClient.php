<?php

namespace App\Api\Wildberries;

use App\Entity\Company;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WildberriesReportsApiClient
{
    private const BASE_URL = 'https://statistics-api.wildberries.ru/api/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    /**
     * @throws TransportExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function fetchDetailedSales(Company $company, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, array $query = []): array
    {
        $apiKey = $company->getWildberriesApiKey();
        if (!$apiKey) {
            throw new \InvalidArgumentException('Wildberries API key is not configured for company '.$company->getId());
        }

        $params = array_merge([
            'dateFrom' => $dateFrom->format(\DATE_ATOM),
            'dateTo' => $dateTo->format(\DATE_ATOM),
            'flag' => 0,
        ], $query);

        return $this->request($apiKey, '/supplier/sales', $params);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws DecodingExceptionInterface
     */
    private function request(string $apiKey, string $path, array $query): array
    {
        $response = $this->httpClient->request('GET', rtrim(self::BASE_URL, '/').$path, [
            'headers' => $this->headers($apiKey),
            'query' => $query,
        ]);

        $this->logger->info('Wildberries API request', [
            'path' => $path,
            'query' => $query,
            'status' => $response->getStatusCode(),
        ]);

        return $response->toArray(false);
    }

    private function headers(string $apiKey): array
    {
        return [
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
        ];
    }
}
