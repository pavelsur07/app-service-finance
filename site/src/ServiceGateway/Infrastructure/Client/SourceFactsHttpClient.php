<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\SourceFactBatch;
use App\ServiceGateway\Application\DTO\SourceFactBatchesPage;
use App\ServiceGateway\Application\DTO\SourceFactPage;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `/internal/source-facts*` одного сервиса по внутренней сети. Токен — направления
 * «Symfony → сервис». Повторов внутри вызова нет: повтор — следующий запуск cron.
 *
 * Тело ответа не логируется. `payload_hash` не пересчитывается (контракт: идентификатор версии
 * записи); целостность страницы — `record_count`.
 */
final readonly class SourceFactsHttpClient implements SourceFactsClientInterface
{
    public const CONTRACT = 'source-fact-envelope/v1';
    private const SCHEMA_VERSION = '1';
    private const IDLE_TIMEOUT_SECONDS = 5.0;
    private const MAX_DURATION_SECONDS = 30.0;
    private const SHA256 = '/^[0-9a-f]{64}$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $service,
        private ?string $baseUrl,
        private ?string $token,
    ) {
    }

    public function service(): string
    {
        return $this->service;
    }

    public function isEnabled(): bool
    {
        return '' !== (string) $this->baseUrl && '' !== (string) $this->token;
    }

    public function fetchBatches(string $resourceType, ?string $after, int $limit): SourceFactBatchesPage
    {
        $query = ['resource_type' => $resourceType, 'limit' => $limit];
        if (null !== $after) {
            $query['after'] = $after;
        }
        $data = $this->decode($this->request('GET', '/internal/source-facts', ['query' => $query]));
        $this->envelope($data);

        $items = $data->batches ?? null;
        if (!\is_array($items)) {
            throw $this->violation('$.batches', 'must be an array');
        }
        $batches = [];
        foreach ($items as $i => $item) {
            $path = sprintf('$.batches[%d]', $i);
            if (!$item instanceof \stdClass) {
                throw $this->violation($path, 'must be an object');
            }
            if ($resourceType !== ($item->resource_type ?? null)) {
                throw $this->violation($path.'.resource_type', 'must equal the requested resource');
            }
            $contentHash = $item->content_hash ?? null;
            if (!\is_string($contentHash) || 1 !== preg_match(self::SHA256, $contentHash)) {
                throw $this->violation($path.'.content_hash', 'must be sha256 hex');
            }
            $batches[] = new SourceFactBatch(
                service: $this->service,
                batchId: $this->text($item, 'batch_id', 255, $path),
                connectionRef: $this->text($item, 'connection_ref', 64, $path),
                companyId: null,
                resourceType: $resourceType,
                periodKey: $this->text($item, 'period_key', 32, $path),
                revision: $this->positive($item, 'revision', $path),
                contentHash: $contentHash,
                pageCount: $this->positive($item, 'page_count', $path),
            );
        }
        $next = $data->next_cursor ?? null;
        if (null !== $next && (!\is_string($next) || '' === $next)) {
            throw $this->violation('$.next_cursor', 'must be a non-empty string or null');
        }

        return new SourceFactBatchesPage($batches, $next);
    }

    public function fetchPage(string $batchId, int $page): SourceFactPage
    {
        $body = $this->request('GET', '/internal/source-facts/page', ['query' => ['batch_id' => $batchId, 'page' => $page]], permanent: [404, 502]);
        $data = $this->decode($body);
        $this->envelope($data);

        if ($batchId !== ($data->batch_id ?? null) || $page !== ($data->page ?? null)) {
            throw $this->violation('$.batch_id', 'batch or page differs from the requested one');
        }
        $records = $data->records ?? null;
        if (!\is_array($records)) {
            throw $this->violation('$.records', 'must be an array');
        }
        $recordCount = $data->record_count ?? null;
        if (\count($records) !== $recordCount) {
            throw $this->violation('$.record_count', 'must equal the number of records');
        }
        foreach ($records as $i => $record) {
            $path = sprintf('$.records[%d]', $i);
            if (!$record instanceof \stdClass) {
                throw $this->violation($path, 'must be an object');
            }
            $this->text($record, 'source_key', 255, $path);
            $hash = $record->payload_hash ?? null;
            if (!\is_string($hash) || 1 !== preg_match(self::SHA256, $hash)) {
                throw $this->violation($path.'.payload_hash', 'must be sha256 hex');
            }
            if (!($record->payload ?? null) instanceof \stdClass) {
                throw $this->violation($path.'.payload', 'must be an object');
            }
        }

        return new SourceFactPage($batchId, $page, $this->positive($data, 'page_count', '$'), $recordCount, $body);
    }

    public function ack(array $batchIds): int
    {
        $data = $this->decode($this->request('POST', '/internal/source-facts/ack', ['json' => ['batch_ids' => $batchIds]]));
        $acked = $data->acked ?? null;
        if (self::SCHEMA_VERSION !== ($data->schema_version ?? null) || !\is_int($acked) || $acked < 0) {
            throw $this->violation('$.acked', 'must be a non-negative integer of schema_version 1');
        }

        return $acked;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $permanent статусы, при которых повтор бесполезен: нарушение контракта, не «недоступен»
     */
    private function request(string $method, string $path, array $options, array $permanent = []): string
    {
        if (!$this->isEnabled()) {
            throw ServiceUnavailableException::notConfigured($this->service);
        }
        $url = rtrim((string) $this->baseUrl, '/').$path;
        $traceId = Uuid::uuid7()->toString();
        $startedAt = microtime(true);
        $context = ['service' => $this->service, 'method' => $method, 'url' => $url, 'traceId' => $traceId];

        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => 'application/json',
                    'X-Trace-Id' => $traceId,
                ],
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Source facts request failed.', $context + [
                'durationMs' => $this->elapsedMs($startedAt),
                'exceptionClass' => $e::class,
            ]);

            throw ServiceUnavailableException::transport($this->service, $e);
        }
        $context += ['status' => $status, 'durationMs' => $this->elapsedMs($startedAt)];
        if (200 !== $status) {
            $this->logger->warning('Source facts request failed.', $context);
            if (\in_array($status, $permanent, true)) {
                throw $this->violation('$', sprintf('HTTP %d', $status));
            }

            throw ServiceUnavailableException::status($this->service, $status);
        }
        $this->logger->info('Source facts request done.', $context);

        return $body;
    }

    private function decode(string $body): \stdClass
    {
        try {
            $data = json_decode($body, false, 128, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw $this->violation('$', 'response is not JSON');
        }
        if (!$data instanceof \stdClass) {
            throw $this->violation('$', 'must be an object');
        }

        return $data;
    }

    private function envelope(\stdClass $data): void
    {
        if (self::SCHEMA_VERSION !== ($data->schema_version ?? null)) {
            throw $this->violation('$.schema_version', 'unsupported version');
        }
        if ($this->service !== ($data->service ?? null)) {
            throw $this->violation('$.service', sprintf('expected %s', $this->service));
        }
    }

    private function text(\stdClass $item, string $field, int $maxLength, string $path): string
    {
        $value = $item->{$field} ?? null;
        if (!\is_string($value) || '' === $value || \strlen($value) > $maxLength) {
            throw $this->violation($path.'.'.$field, sprintf('must be a non-empty string up to %d bytes', $maxLength));
        }

        return $value;
    }

    private function positive(\stdClass $item, string $field, string $path): int
    {
        $value = $item->{$field} ?? null;
        if (!\is_int($value) || $value < 1) {
            throw $this->violation($path.'.'.$field, 'must be a positive integer');
        }

        return $value;
    }

    private function violation(string $path, string $reason): ContractViolationException
    {
        return ContractViolationException::at(self::CONTRACT, $path, $reason);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
