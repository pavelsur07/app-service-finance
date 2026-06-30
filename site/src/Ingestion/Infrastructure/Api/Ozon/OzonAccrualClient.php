<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OzonAccrualClient implements OzonAccrualClientInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const POSTINGS_ENDPOINT = '/v1/finance/accrual/postings';
    private const BY_DAY_ENDPOINT = '/v1/finance/accrual/by-day';
    private const TYPES_ENDPOINT = '/v1/finance/accrual/types';

    public function __construct(
        private HttpClientInterface $httpClient,
        private OzonCredentialProviderInterface $credentialProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        array $postingNumbers,
    ): OzonRawPage {
        $postingNumbers = $this->normalizePostingNumbers($postingNumbers);

        return $this->requestPage(
            companyId: $companyId,
            connectionRef: $connectionRef,
            endpoint: self::POSTINGS_ENDPOINT,
            json: [
                'posting_numbers' => $postingNumbers,
            ],
        );
    }

    public function fetchByDay(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $date,
        ?string $lastId = null,
    ): OzonRawPage {
        $json = [
            'date' => $date->format('Y-m-d'),
        ];

        if (null !== $lastId && '' !== trim($lastId)) {
            $json['last_id'] = trim($lastId);
        }

        return $this->requestPage(
            companyId: $companyId,
            connectionRef: $connectionRef,
            endpoint: self::BY_DAY_ENDPOINT,
            json: $json,
            lastIdPagination: true,
        );
    }

    public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
    {
        return $this->requestPage(
            companyId: $companyId,
            connectionRef: $connectionRef,
            endpoint: self::TYPES_ENDPOINT,
            json: new \stdClass(),
        );
    }

    /**
     * @param array<string, mixed>|\stdClass $json
     */
    private function requestPage(
        string $companyId,
        string $connectionRef,
        string $endpoint,
        array|\stdClass $json,
        int $page = 1,
        int $pageSize = 0,
        int $offset = 0,
        bool $lastIdPagination = false,
    ): OzonRawPage {
        $payload = $this->requestJson($companyId, $connectionRef, $endpoint, $json);
        $rows = $this->extractRows($payload);
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $nextLastId = $lastIdPagination ? $this->stringValue($result['last_id'] ?? $payload['last_id'] ?? null) : null;
        $total = $this->intValue($result['total'] ?? $payload['total'] ?? null);
        $hasMore = $lastIdPagination
            ? null !== $nextLastId
            : (bool) ($result['has_next'] ?? $result['has_more'] ?? $payload['has_next'] ?? $payload['has_more'] ?? false);

        if (!$hasMore && !$lastIdPagination && $total > 0 && $pageSize > 0) {
            $hasMore = ($offset + \count($rows)) < $total;
        }

        return new OzonRawPage(
            rows: $rows,
            hasMore: $hasMore,
            nextPageToken: $lastIdPagination ? $nextLastId : ($hasMore ? (string) ($page + 1) : null),
            metadata: [
                'endpoint' => $endpoint,
                'page' => $page,
                'pageSize' => $pageSize,
                'offset' => $offset,
                'lastId' => $lastIdPagination && is_array($json) ? ($json['last_id'] ?? null) : null,
                'nextLastId' => $nextLastId,
                'rows' => \count($rows),
                'total' => $total > 0 ? $total : null,
                'resultKeys' => array_keys($result),
            ],
        );
    }

    /**
     * @param array<string, mixed>|\stdClass $json
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $companyId, string $connectionRef, string $endpoint, array|\stdClass $json): array
    {
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
            $content = $response->getContent(false);
            $this->classifyStatus($statusCode, $endpoint, $content);
        } catch (TransportExceptionInterface $exception) {
            throw new ConnectorTransientException(sprintf('Ozon accrual transport error for %s.', $endpoint), previous: $exception);
        } finally {
            $this->logger->info('Ozon accrual API request finished.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'endpoint' => $endpoint,
                'statusCode' => $statusCode,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Ozon accrual returned invalid JSON for %s.', $endpoint), previous: $exception);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Ozon accrual returned unexpected non-object payload for %s.', $endpoint));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        foreach ([$payload['result'] ?? null, $payload] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (array_is_list($candidate)) {
                return $this->objectRows($candidate);
            }

            foreach (['items', 'postings', 'accruals', 'accrual_types', 'operations', 'rows', 'data', 'types'] as $key) {
                $rows = $candidate[$key] ?? null;
                if (is_array($rows) && array_is_list($rows)) {
                    return $this->objectRows($rows);
                }
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function objectRows(array $rows): array
    {
        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && !array_is_list($row)));
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }

    /**
     * @param list<string> $postingNumbers
     *
     * @return non-empty-list<string>
     */
    private function normalizePostingNumbers(array $postingNumbers): array
    {
        $normalized = [];
        foreach ($postingNumbers as $postingNumber) {
            $postingNumber = trim((string) $postingNumber);
            if ('' === $postingNumber) {
                continue;
            }

            $normalized[$postingNumber] = $postingNumber;
        }

        $normalized = array_values($normalized);
        if ([] === $normalized) {
            throw new \InvalidArgumentException('At least one Ozon posting number is required.');
        }

        if (\count($normalized) > 200) {
            throw new \InvalidArgumentException('Ozon accrual postings request supports at most 200 posting numbers.');
        }

        return $normalized;
    }

    /**
     * @return array{api_key: string, client_id: string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        try {
            $credentials = $this->credentialProvider->read($companyId, $connectionRef);
        } catch (CredentialNotFoundException $exception) {
            throw new ConnectorAuthException('Ozon Seller credentials were not found.', previous: $exception);
        }

        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        $clientId = trim((string) ($credentials['client_id'] ?? ''));

        if ('' === $apiKey || '' === $clientId) {
            throw new ConnectorAuthException('Ozon Seller credentials are incomplete.');
        }

        return ['api_key' => $apiKey, 'client_id' => $clientId];
    }

    private function classifyStatus(int $statusCode, string $endpoint, string $content): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException(sprintf('Ozon accrual API auth failed for %s.', $endpoint));
        }

        if (400 === $statusCode && $this->isCredentialBadRequest($content)) {
            throw new ConnectorAuthException(sprintf('Ozon accrual API auth failed for %s.', $endpoint));
        }

        if (429 === $statusCode) {
            throw new ConnectorTransientException(sprintf('Ozon accrual API rate limit for %s.', $endpoint));
        }

        if ($statusCode >= 500) {
            throw new ConnectorTransientException(sprintf('Ozon accrual API server error %d for %s.', $statusCode, $endpoint));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Ozon accrual API returned HTTP %d for %s.', $statusCode, $endpoint));
        }
    }

    private function isCredentialBadRequest(string $content): bool
    {
        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($payload)) {
            return false;
        }

        // Ozon uses code=3 both for credential problems and ordinary request validation
        // errors, for example invalid accrual posting_numbers. Classify only by text.
        return $this->containsCredentialError($payload);
    }

    /**
     * @param array<mixed> $payload
     */
    private function containsCredentialError(array $payload): bool
    {
        foreach ($payload as $value) {
            if (is_array($value) && $this->containsCredentialError($value)) {
                return true;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $text = strtolower((string) $value);
            foreach (['client-id', 'client id', 'api-key', 'api key', 'credential', 'auth', 'unauthorized', 'forbidden'] as $needle) {
                if (str_contains($text, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
