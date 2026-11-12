<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\OzonFetcher;

use App\Inventory\Exception\OzonSupplySnapshotUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GET /internal/snapshots/latest ozon-fetcher (контракт services/_contracts/service-snapshot/v1),
 * ресурс `ozon.supplies`. Токен направления «Symfony → сервис» — `OZON_FETCHER_API_TOKEN`.
 */
final readonly class OzonSupplySnapshotClient
{
    public const RESOURCE = 'ozon.supplies';
    public const ENDPOINT = '/internal/snapshots/latest';
    private const IDLE_TIMEOUT_SECONDS = 10.0;
    private const MAX_DURATION_SECONDS = 60.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $baseUrl,
        private ?string $token,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== (string) $this->baseUrl && '' !== (string) $this->token;
    }

    /**
     * null — у кабинета ещё нет загруженного снимка (404 `snapshot_not_found`).
     *
     * @throws OzonSupplySnapshotUnavailableException сеть или статус 5xx/прочий — временно, повтор завтра
     * @throws \UnexpectedValueException ответ вне контракта или 401/403/422 — дефект, будить человека
     */
    public function fetchLatest(string $connectionRef): ?OzonSupplySnapshot
    {
        $url = rtrim((string) $this->baseUrl, '/').self::ENDPOINT;
        $traceId = Uuid::uuid7()->toString();
        $startedAt = microtime(true);
        $context = ['method' => 'GET', 'url' => $url, 'resourceType' => self::RESOURCE, 'connectionRef' => $connectionRef, 'traceId' => $traceId];

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => ['connection_ref' => $connectionRef, 'resource_type' => self::RESOURCE],
                'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json', 'X-Trace-Id' => $traceId],
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = 200 === $status ? $response->getContent(false) : '';
        } catch (ExceptionInterface $e) {
            throw new OzonSupplySnapshotUnavailableException(sprintf('ozon-fetcher snapshot request failed: %s', $e::class), 0, $e);
        }
        $context += ['status' => $status, 'durationMs' => (int) round((microtime(true) - $startedAt) * 1000)];
        $this->logger->info('Ozon supply snapshot request.', $context);

        if (404 === $status) {
            return null;
        }
        if (in_array($status, [401, 403, 422], true)) {
            // Токен или запрос не приняты — конфигурация/дефект, сам не пройдёт.
            throw new \UnexpectedValueException(sprintf('ozon-fetcher rejected snapshot request: status %d', $status));
        }
        if (200 !== $status) {
            throw new OzonSupplySnapshotUnavailableException(sprintf('ozon-fetcher snapshot status %d', $status));
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('ozon-fetcher snapshot is not JSON');
        }
        if (!is_array($data) || '1' !== ($data['schema_version'] ?? null) || self::RESOURCE !== ($data['resource_type'] ?? null)
            || $connectionRef !== ($data['connection_ref'] ?? null) || !is_array($data['pages'] ?? null) || !array_is_list($data['pages'])) {
            throw new \UnexpectedValueException('ozon-fetcher snapshot violates service-snapshot v1');
        }
        $periodDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($data['period_key'] ?? ''));
        $loadedAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) ($data['loaded_at'] ?? ''));
        if (false === $periodDate || false === $loadedAt || $periodDate->format('Y-m-d') !== ($data['period_key'] ?? null)) {
            throw new \UnexpectedValueException('ozon-fetcher snapshot has invalid period_key or loaded_at');
        }

        /* @var array<string, mixed> $data */
        return new OzonSupplySnapshot($periodDate, $loadedAt, $data['pages'], $data);
    }
}
