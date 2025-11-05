<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Adapter;

use App\Entity\Company;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WB Statistics API v5 — reportDetailByPeriod (детализация финансовых отчётов).
 *
 * Эндпойнт:
 *   GET https://statistics-api.wildberries.ru/api/v5/supplier/reportDetailByPeriod
 *
 * Параметры:
 *   - dateFrom: RFC3339 (ATOM), напр. 2025-10-01T00:00:00+03:00
 *   - dateTo:   YYYY-MM-DD (по спецификации WB этот параметр часто указывается датой)
 *   - rrdid:    int (курсор постраничной загрузки), 0 для первого запроса
 *   - period:   daily|weekly
 *
 * Возвращает:
 *   - array — массив строк отчёта (или пустой массив, если всё выгружено)
 */
final class WildberriesStatisticsV5Client
{
    private const BASE_URL = 'https://statistics-api.wildberries.ru/api/v5';
    private const ENDPOINT = '/supplier/reportDetailByPeriod';

    private HttpClientInterface $http;
    private LoggerInterface $logger;
    /**
     * Не делаем жёсткую зависимость — если в DI не подключён лимитер, код продолжит работать.
     * Ожидается совместимость с вашим ReportsApiRateLimiter (если есть).
     *
     * @var object|null
     */
    private $rateLimiter;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
        ?object $rateLimiter = null, // совместимо с отсутствием сервиса
    ) {
        $this->http = $http;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Возвращает массив строк отчёта (одна "страница") или [].
     *
     * @throws \RuntimeException при сетевых/HTTP ошибках после исчерпания ретраев
     */
    public function fetchReportDetailByPeriod(
        Company $company,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $rrdId = 0,
        string $period = 'daily',
    ): array {
        $token = trim((string) $company->getWildberriesApiKey());
        if ('' === $token) {
            throw new \RuntimeException('WB API key is missing for company '.$company->getId());
        }

        $query = [
            'dateFrom' => $dateFrom->format(\DATE_ATOM), // RFC3339
            'dateTo' => $dateTo->format('Y-m-d'),      // WB принимает дату
            'rrdid' => $rrdId,
            'period' => $period,                        // daily|weekly
        ];

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];

        // лимитер (если есть)
        $this->consumeRateLimit($company, 'reportDetailByPeriod');

        $url = self::BASE_URL.self::ENDPOINT;

        $attempt = 0;
        $maxAttempts = 5;
        $backoffMs = 500; // стартовая пауза ретрая, затем экспоненциально растёт

        while (true) {
            ++$attempt;
            $startedAt = microtime(true);

            try {
                $response = $this->http->request('GET', $url, [
                    'headers' => $headers,
                    'query' => $query,
                    // 'timeout' => 30.0, // при необходимости
                ]);

                $status = $response->getStatusCode();
                $retryAfter = $this->parseRetryAfter($response->getHeaders(false));

                // 2xx — ок
                if ($status >= 200 && $status < 300) {
                    $data = $response->toArray(false);

                    $this->logger->info('WB v5 reportDetailByPeriod ok', [
                        'company_id' => (string) $company->getId(),
                        'status' => $status,
                        'query' => $query,
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        'rows' => is_array($data) ? count($data) : null,
                        'attempt' => $attempt,
                    ]);

                    return is_array($data) ? $data : [];
                }

                // 429/5xx — пробуем ретрай
                if (429 === $status || ($status >= 500 && $status <= 599)) {
                    if ($attempt >= $maxAttempts) {
                        $this->logger->error('WB v5 reportDetailByPeriod failed after retries', [
                            'company_id' => (string) $company->getId(),
                            'status' => $status,
                            'query' => $query,
                            'attempts' => $attempt,
                        ]);
                        throw new \RuntimeException("WB v5 API responded with status {$status} after {$attempt} attempts");
                    }

                    $sleepMs = $this->nextBackoffMs($backoffMs, $retryAfter);
                    $this->logger->warning('WB v5 reportDetailByPeriod retrying', [
                        'company_id' => (string) $company->getId(),
                        'status' => $status,
                        'query' => $query,
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMs,
                    ]);
                    usleep($sleepMs * 1000);
                    $backoffMs *= 2;
                    continue;
                }

                // прочие ошибки — без ретраев
                $body = null;
                try {
                    $body = $response->getContent(false);
                } catch (\Throwable) {
                }

                $this->logger->error('WB v5 reportDetailByPeriod unexpected status', [
                    'company_id' => (string) $company->getId(),
                    'status' => $status,
                    'query' => $query,
                    'body' => $body,
                ]);

                throw new \RuntimeException("WB v5 API unexpected status {$status}");
            } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
                if ($attempt >= $maxAttempts) {
                    $this->logger->error('WB v5 reportDetailByPeriod transport error after retries', [
                        'company_id' => (string) $company->getId(),
                        'query' => $query,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException('WB v5 API transport error: '.$e->getMessage(), previous: $e);
                }

                $sleepMs = $this->nextBackoffMs($backoffMs, null);
                $this->logger->warning('WB v5 reportDetailByPeriod transport retry', [
                    'company_id' => (string) $company->getId(),
                    'query' => $query,
                    'attempt' => $attempt,
                    'sleep_ms' => $sleepMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($sleepMs * 1000);
                $backoffMs *= 2;
                continue;
            }
        }
    }

    private function consumeRateLimit(Company $company, string $methodKey): void
    {
        if (null === $this->rateLimiter) {
            return;
        }

        // Пытаемся вызвать метод consume по «мягкому контракту», чтобы не ломать существующий код.
        // Пример ожидаемого вызова: $rateLimiter->consume('companyId:wb:v5:reportDetailByPeriod')
        $key = sprintf('%s:wb:v5:%s', (string) $company->getId(), $methodKey);

        try {
            if (method_exists($this->rateLimiter, 'consume')) {
                $this->rateLimiter->consume($key);
            }
        } catch (\Throwable $e) {
            // Лимитер не должен падать сквозь — логируем и идём дальше.
            $this->logger->warning('ReportsApiRateLimiter consume error', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Возвращает milliseconds для паузы ретрая.
     * Если сервер прислал Retry-After (в секундах) — используем его, но не меньше экспоненциального backoff.
     */
    private function nextBackoffMs(int $expBackoffMs, ?int $retryAfterSec): int
    {
        $serverMs = (null !== $retryAfterSec) ? max(0, $retryAfterSec) * 1000 : null;

        return (int) max($expBackoffMs, $serverMs ?? 0);
    }

    /**
     * Пытаемся извлечь Retry-After из заголовков ответа, если он есть.
     * Ожидается целое число секунд.
     */
    private function parseRetryAfter(array $headers): ?int
    {
        // $headers — массив вида ['retry-after' => ['3'], 'content-type' => ['application/json'], ...]
        foreach ($headers as $name => $values) {
            if (0 === \strcasecmp($name, 'retry-after') && isset($values[0])) {
                $v = trim((string) $values[0]);
                if (ctype_digit($v)) {
                    return (int) $v;
                }
            }
        }

        return null;
    }
}
