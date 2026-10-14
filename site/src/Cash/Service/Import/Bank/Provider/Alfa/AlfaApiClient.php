<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\Bank\Provider\Alfa;

use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Exception\BankApiRequestFailedException;
use App\Cash\Exception\BankApiUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AlfaApiClient
{
    private const BANK_CODE = 'alfa';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getAccounts(BankConnection $connection): array
    {
        return $this->request($connection, 'GET', '/api/jp/open-banking/v1.3/accounts');
    }

    /**
     * @return array<mixed>
     */
    public function getTransactions(
        BankConnection $connection,
        string $accountNumber,
        \DateTimeInterface $date,
        int $page,
    ): array {
        return $this->request($connection, 'GET', '/jp/v1/statement/transactions', [
            'query' => [
                'accountNumber' => $accountNumber,
                'statementDate' => $date->format('Y-m-d'),
                'page' => $page,
            ],
        ]);
    }

    /**
     * В лог идёт путь без query: в query номер счёта. Тело ответа не логируется.
     *
     * Исключения HttpClient не передаются как previous: Symfony вписывает в их
     * сообщение полный URL с query, и номер счёта ушёл бы в GlitchTip через
     * цепочку исключений. Вместо этого класс исходного исключения — в логе.
     *
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(BankConnection $connection, string $method, string $path, array $options = []): array
    {
        $url = rtrim($connection->getBaseUrl(), '/').'/'.ltrim($path, '/');
        $startedAt = microtime(true);
        $context = [
            'method' => $method,
            'path' => $path,
            'bank_connection_id' => $connection->getId(),
        ];

        try {
            $response = $this->httpClient->request($method, $url, array_merge([
                'headers' => $this->buildHeaders($connection),
            ], $options));
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Alfa API request failed: transport error', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new BankApiUnavailableException(self::BANK_CODE, null);
        }

        $context['status_code'] = $statusCode;

        if (200 !== $statusCode) {
            $context['duration_ms'] = $this->elapsedMs($startedAt);

            if (\in_array($statusCode, [408, 429], true) || $statusCode >= 500) {
                $this->logger->warning('Alfa API temporarily unavailable', $context);

                throw new BankApiUnavailableException(self::BANK_CODE, $statusCode);
            }

            $this->logger->warning('Alfa API rejected the request', $context);

            throw new BankApiRequestFailedException(self::BANK_CODE, $statusCode);
        }

        try {
            $payload = $response->toArray(false);
        } catch (DecodingExceptionInterface $exception) {
            $this->logger->warning('Alfa API returned an unreadable response', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new BankApiRequestFailedException(self::BANK_CODE, null);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Alfa API request failed: response body interrupted', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new BankApiUnavailableException(self::BANK_CODE, null);
        }

        $context['duration_ms'] = $this->elapsedMs($startedAt);
        $this->logger->info('Alfa API request', $context);

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(BankConnection $connection): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $connection->getApiKey()),
            'x-fapi-interaction-id' => Uuid::uuid4()->toString(),
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
