<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GET /internal/coverage одного сервиса по внутренней сети; экземпляр на сервис
 * (`config/services.yaml`, тег `app.service_coverage_client`).
 *
 * Токен — направления «Symfony → сервис» (`OZON_FETCHER_API_TOKEN`,
 * `WB_FETCHER_API_TOKEN`), не `INTERNAL_AUTH_TOKEN` (service-gateway.md §2.9).
 * Повторов внутри вызова нет: повтор — следующий запуск cron через 10 минут.
 */
final readonly class ServiceCoverageHttpClient implements ServiceCoverageClientInterface
{
    private const IDLE_TIMEOUT_SECONDS = 2.0;
    private const MAX_DURATION_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ServiceCoverageV1Parser $parser,
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

    public function fetchCoverage(): ServiceCoverageReport
    {
        $url = rtrim((string) $this->baseUrl, '/').'/internal/coverage';
        $traceId = Uuid::uuid7()->toString();
        $startedAt = microtime(true);
        $context = ['service' => $this->service, 'method' => 'GET', 'url' => $url, 'traceId' => $traceId];

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => 'application/json',
                    'X-Trace-Id' => $traceId,
                ],
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $context += ['status' => $status, 'durationMs' => $this->elapsedMs($startedAt)];
            if (200 !== $status) {
                $this->logger->warning('Service coverage request failed.', $context);

                throw ServiceUnavailableException::status($this->service, $status);
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Service coverage request failed.', $context + [
                'durationMs' => $this->elapsedMs($startedAt),
                'exceptionClass' => $e::class,
            ]);

            throw ServiceUnavailableException::transport($this->service, $e);
        }

        try {
            // Объектами, не массивами: иначе `"rows": {}` неотличим от `[]` (см. парсер).
            $data = json_decode($body, false, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ContractViolationException::at(ServiceCoverageV1Parser::CONTRACT, '$', 'response is not JSON');
        }
        $report = $this->parser->parse($data);
        if ($this->service !== $report->service) {
            throw ContractViolationException::at(ServiceCoverageV1Parser::CONTRACT, '$.service', sprintf('expected %s', $this->service));
        }
        $this->logger->info('Service coverage fetched.', $context + ['rows' => \count($report->rows)]);

        return $report;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
