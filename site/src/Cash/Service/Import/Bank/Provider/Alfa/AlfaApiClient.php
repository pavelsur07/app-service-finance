<?php

namespace App\Cash\Service\Import\Bank\Provider\Alfa;

use App\Cash\Entity\Bank\BankConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlfaApiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function getAccounts(BankConnection $connection): array
    {
        return $this->request($connection, 'GET', '/api/jp/open-banking/v1.3/accounts');
    }

    public function getTransactions(
        BankConnection $connection,
        string $accountNumber,
        \DateTimeInterface $date,
        int $page
    ): array {
        return $this->request($connection, 'GET', '/jp/v1/statement/transactions', [
            'query' => [
                'accountNumber' => $accountNumber,
                'statementDate' => $date->format('Y-m-d'),
                'page' => $page,
            ],
        ]);
    }

    private function request(BankConnection $connection, string $method, string $path, array $options = []): array
    {
        $url = rtrim($connection->getBaseUrl(), '/').'/'.ltrim($path, '/');
        $response = $this->httpClient->request($method, $url, array_merge([
            'headers' => $this->buildHeaders($connection),
        ], $options));

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode) {
            $this->logger->warning('Alfa API request failed', [
                'status_code' => $statusCode,
                'path' => $path,
                'bank_connection_id' => $connection->getId(),
            ]);

            throw new RuntimeException(sprintf('Alfa API request failed with status %d', $statusCode));
        }

        try {
            return $response->toArray(false);
        } catch (DecodingExceptionInterface $exception) {
            throw new RuntimeException('Failed to decode Alfa API response', 0, $exception);
        }
    }

    private function buildHeaders(BankConnection $connection): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $connection->getApiKey()),
            'x-fapi-interaction-id' => Uuid::uuid4()->toString(),
        ];
    }
}
