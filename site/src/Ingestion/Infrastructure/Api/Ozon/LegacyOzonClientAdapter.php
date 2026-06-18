<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class LegacyOzonClientAdapter implements OzonClientAdapterInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const TRANSACTION_LIST_ENDPOINT = '/v3/finance/transaction/list';
    private const REALIZATION_ENDPOINT = '/v2/finance/realization';

    public function __construct(
        private HttpClientInterface $httpClient,
        private OzonCredentialProviderInterface $credentialProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchTransactionList(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $page,
        int $pageSize,
    ): OzonRawPage {
        if ($from > $to) {
            throw new \InvalidArgumentException('Ozon transaction list dateFrom cannot be later than dateTo.');
        }

        $payload = $this->requestJson(
            companyId: $companyId,
            connectionRef: $connectionRef,
            endpoint: self::TRANSACTION_LIST_ENDPOINT,
            json: [
                'filter' => [
                    'date' => [
                        'from' => $from->format('Y-m-d\T00:00:00.000\Z'),
                        'to' => $to->format('Y-m-d\T23:59:59.000\Z'),
                    ],
                    'transaction_type' => 'all',
                    'operation_type' => [],
                    'posting_number' => '',
                ],
                'page' => $page,
                'page_size' => $pageSize,
            ],
        );

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException('Ozon transaction list returned unexpected payload: result is missing.');
        }

        $operations = $result['operations'] ?? [];
        if (!is_array($operations)) {
            throw new \RuntimeException('Ozon transaction list returned unexpected payload: operations is not an array.');
        }

        $pageCount = max($page, (int) ($result['page_count'] ?? $page));

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($operations, static fn (mixed $row): bool => is_array($row)));

        return new OzonRawPage(
            rows: $rows,
            hasMore: $page < $pageCount,
            nextPageToken: $page < $pageCount ? (string) ($page + 1) : null,
            metadata: ['page' => $page, 'pageCount' => $pageCount],
        );
    }

    public function fetchRealization(
        string $companyId,
        string $connectionRef,
        int $year,
        int $month,
    ): OzonRawPage {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(sprintf('Invalid Ozon realization month: %d.', $month));
        }

        $payload = $this->requestJson(
            companyId: $companyId,
            connectionRef: $connectionRef,
            endpoint: self::REALIZATION_ENDPOINT,
            json: [
                'month' => $month,
                'year' => $year,
            ],
        );

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException('Ozon realization returned unexpected payload: result is missing.');
        }

        $rows = $result['rows'] ?? [];
        if (!is_array($rows)) {
            throw new \RuntimeException('Ozon realization returned unexpected payload: rows is not an array.');
        }

        $header = is_array($result['header'] ?? null) ? $result['header'] : [];
        $headerAdditional = is_array($result['header_additional'] ?? null) ? $result['header_additional'] : [];

        /** @var list<array<string, mixed>> $rowList */
        $rowList = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));

        return new OzonRawPage(
            rows: $rowList,
            hasMore: false,
            metadata: [
                'year' => $year,
                'month' => $month,
                'header' => $header,
                'headerAdditional' => $headerAdditional,
            ],
        );
    }

    /**
     * @return list<OzonShopDescriptor>
     */
    public function listClusters(string $companyId, string $connectionRef): array
    {
        $this->credentials($companyId, $connectionRef);

        return [
            new OzonShopDescriptor(
                externalId: $connectionRef,
                name: 'Ozon Seller',
                currency: 'RUB',
                metadata: ['connectionRef' => $connectionRef],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function requestJson(
        string $companyId,
        string $connectionRef,
        string $endpoint,
        array $json,
    ): array {
        $credentials = $this->credentials($companyId, $connectionRef);
        $startedAt = microtime(true);
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.$endpoint, [
                'headers' => [
                    'Client-Id' => $credentials['client_id'],
                    'Api-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => $json,
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $this->classifyStatus($statusCode, $endpoint);

            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new ConnectorTransientException(sprintf('Ozon transport error for %s.', $endpoint), previous: $exception);
        } finally {
            $this->logger->info('Ozon Seller API request finished.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'endpoint' => $endpoint,
                'httpStatus' => $statusCode,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Ozon returned invalid JSON for %s.', $endpoint), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Ozon returned unexpected non-object payload for %s.', $endpoint));
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{api_key: string, client_id: string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        try {
            $payload = $this->credentialProvider->read($companyId, $connectionRef);
        } catch (CredentialNotFoundException $exception) {
            throw new ConnectorAuthException('Ozon Seller credentials were not found.', previous: $exception);
        }

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        $clientId = trim((string) ($payload['client_id'] ?? ''));

        if ('' === $apiKey || '' === $clientId) {
            throw new ConnectorAuthException('Ozon Seller credentials are incomplete.');
        }

        return ['api_key' => $apiKey, 'client_id' => $clientId];
    }

    private function classifyStatus(int $statusCode, string $endpoint): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException(sprintf('Ozon Seller API auth failed for %s.', $endpoint));
        }

        if (429 === $statusCode) {
            throw new ConnectorTransientException(sprintf('Ozon Seller API rate limit for %s.', $endpoint));
        }

        if ($statusCode >= 500) {
            throw new ConnectorTransientException(sprintf('Ozon Seller API server error %d for %s.', $statusCode, $endpoint));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Ozon Seller API returned HTTP %d for %s.', $statusCode, $endpoint));
        }
    }
}
