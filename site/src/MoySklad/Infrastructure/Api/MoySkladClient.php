<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Api;

use App\MoySklad\Application\DTO\ConnectionCheckResult;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\CatalogSyncException;
use App\MoySklad\Exception\CounterpartySyncException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MoySkladClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'MOYSKLAD_API_BASE_URL')]
        private string $baseUrl,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function check(#[\SensitiveParameter] string $token): ConnectionCheckResult
    {
        if (strlen($token) > 8192 || 1 !== preg_match('/^[\x21-\x7E]+$/D', $token)) {
            return new ConnectionCheckResult(ConnectionCheckStatus::INVALID_TOKEN);
        }

        $httpClient = $this->httpClient instanceof RetryableHttpClient
            ? $this->httpClient->withOptions(['max_retries' => 0])
            : $this->httpClient;

        $url = rtrim($this->baseUrl, '/').'/context/employee';
        $startedAt = hrtime(true);
        $statusCode = null;
        try {
            $response = $httpClient->request('GET', $url, [
                'auth_bearer' => $token,
                'headers' => ['Accept' => 'application/json;charset=utf-8'],
                'timeout' => 10.0,
                'max_duration' => 10.0,
                'max_redirects' => 0,
                'extra' => ['trace_content' => false],
            ]);
            $statusCode = $response->getStatusCode();
            if (429 === $statusCode || $statusCode >= 500) {
                $response->cancel();

                return new ConnectionCheckResult(429 === $statusCode ? ConnectionCheckStatus::RATE_LIMITED : ConnectionCheckStatus::UNAVAILABLE);
            }
            if ($statusCode >= 300 && $statusCode < 400) {
                $response->cancel();

                return new ConnectionCheckResult(ConnectionCheckStatus::INVALID_RESPONSE);
            }
            $data = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            $statusCode = null;

            return new ConnectionCheckResult(ConnectionCheckStatus::UNAVAILABLE);
        } finally {
            $safeUrl = (parse_url($url, \PHP_URL_SCHEME) ?: 'https').'://'.(parse_url($url, \PHP_URL_HOST) ?: '').(parse_url($url, \PHP_URL_PATH) ?: '');
            $this->logger?->log(null === $statusCode || $statusCode >= 400 ? 'warning' : 'info', 'MoySklad connection check request', [
                'method' => 'GET',
                'url' => $safeUrl,
                'httpStatus' => $statusCode,
                'durationMs' => (hrtime(true) - $startedAt) / 1_000_000,
            ]);
        }

        if (200 === $statusCode) {
            $accountId = is_array($data) ? ($data['accountId'] ?? null) : null;
            if (is_string($accountId) && Uuid::isValid($accountId)) {
                return new ConnectionCheckResult(ConnectionCheckStatus::CONNECTED, strtolower($accountId));
            }

            return new ConnectionCheckResult(ConnectionCheckStatus::INVALID_RESPONSE);
        }

        $errors = is_array($data) ? ($data['errors'] ?? []) : [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                $code = is_array($error) ? ($error['code'] ?? null) : null;
                if (47000 === $code || 55006 === $code) {
                    return new ConnectionCheckResult(47000 === $code ? ConnectionCheckStatus::TARIFF_RESTRICTED : ConnectionCheckStatus::UNSUPPORTED_TOKEN);
                }
            }
        }

        return new ConnectionCheckResult(match ($statusCode) {
            401 => ConnectionCheckStatus::INVALID_TOKEN,
            403 => ConnectionCheckStatus::FORBIDDEN,
            default => ConnectionCheckStatus::INVALID_RESPONSE,
        });
    }

    /** @return array{meta: array<string, mixed>, rows: list<array<string, mixed>>} */
    public function fetchCounterpartyPage(#[\SensitiveParameter] string $token, bool $archived, int $offset, int $limit = 100): array
    {
        try {
            return $this->fetchPage($token, 'counterparty', $archived, $offset, $limit);
        } catch (CatalogSyncException $error) {
            throw new CounterpartySyncException($error->category, $error->retryAfterMs);
        }
    }

    /** @return array{meta: array<string, mixed>, rows: list<array<string, mixed>>} */
    public function fetchCatalogPage(#[\SensitiveParameter] string $token, string $entityType, bool $archived, int $offset, int $limit = 100): array
    {
        if (!in_array($entityType, ['product', 'variant'], true)) {
            throw new \InvalidArgumentException('Invalid catalog entity type.');
        }

        return $this->fetchPage($token, $entityType, $archived, $offset, $limit);
    }

    /** @return array{meta: array<string, mixed>, rows: list<array<string, mixed>>} */
    private function fetchPage(#[\SensitiveParameter] string $token, string $entityType, bool $archived, int $offset, int $limit): array
    {
        if (strlen($token) > 8192 || 1 !== preg_match('/^[\x21-\x7E]+$/D', $token)) {
            throw new CatalogSyncException('auth');
        }
        if ($offset < 0 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid MoySklad page coordinates.');
        }

        $httpClient = $this->httpClient instanceof RetryableHttpClient
            ? $this->httpClient->withOptions(['max_retries' => 0])
            : $this->httpClient;
        $url = rtrim($this->baseUrl, '/').'/entity/'.$entityType;
        $query = http_build_query([
            'limit' => $limit,
            'offset' => $offset,
            'order' => 'id,asc',
            'filter' => 'archived='.($archived ? 'true' : 'false'),
        ], '', '&', \PHP_QUERY_RFC3986);
        $statusCode = null;
        $startedAt = hrtime(true);
        try {
            $response = $httpClient->request('GET', $url.'?'.$query, [
                'auth_bearer' => $token,
                'headers' => ['Accept' => 'application/json;charset=utf-8'],
                'timeout' => 10.0,
                'max_duration' => 20.0,
                'max_redirects' => 0,
                'extra' => ['trace_content' => false],
            ]);
            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $retryAfterMs = null;
                if (429 === $statusCode) {
                    $header = $response->getHeaders(false)['x-lognex-retry-after'][0] ?? null;
                    if (is_string($header) && preg_match('/^\d{1,7}$/D', $header)) {
                        $retryAfterMs = min(3_600_000, (int) $header);
                    }
                }
                $response->cancel();
                throw new CatalogSyncException(match (true) {
                    401 === $statusCode => 'auth', 403 === $statusCode => 'forbidden', 429 === $statusCode => 'rate_limited', $statusCode >= 500 => 'temporary', default => 'invalid_request',
                }, $retryAfterMs);
            }

            try {
                $body = $response->getContent(false);
                $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
                $shape = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new CatalogSyncException('invalid_response');
            }
            if (!is_array($data) || !$shape instanceof \stdClass || !isset($shape->rows) || !is_array($shape->rows) || !isset($data['meta'], $data['rows']) || !is_array($data['meta']) || !array_is_list($data['rows']) || !is_int($data['meta']['size'] ?? null) || $data['meta']['size'] < 0 || ($data['meta']['limit'] ?? null) !== $limit || ($data['meta']['offset'] ?? null) !== $offset || count($data['rows']) > $limit) {
                throw new CatalogSyncException('invalid_response');
            }
            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    throw new CatalogSyncException('invalid_response');
                }
            }

            return ['meta' => $data['meta'], 'rows' => $data['rows']];
        } catch (TransportExceptionInterface) {
            $statusCode = null;
            throw new CatalogSyncException('temporary');
        } finally {
            $safeUrl = (parse_url($url, \PHP_URL_SCHEME) ?: 'https').'://'.(parse_url($url, \PHP_URL_HOST) ?: '').(parse_url($url, \PHP_URL_PATH) ?: '');
            $this->logger?->log(null === $statusCode || $statusCode >= 400 ? 'warning' : 'info', 'counterparty' === $entityType ? 'MoySklad counterparty page request' : 'MoySklad catalog page request', [
                'method' => 'GET',
                'url' => $safeUrl,
                'entityType' => $entityType,
                'httpStatus' => $statusCode,
                'durationMs' => (hrtime(true) - $startedAt) / 1_000_000,
            ]);
        }
    }
}
