<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GET /internal/coverage сервиса ozon-fetcher по внутренней сети.
 *
 * Токен — направления «Symfony → сервис» (`OZON_FETCHER_API_TOKEN`), не
 * `INTERNAL_AUTH_TOKEN` (service-gateway.md §2.9). Повторов внутри вызова нет:
 * повтор — следующий запуск cron через 10 минут.
 */
final readonly class OzonFetcherCoverageClient implements ServiceCoverageClientInterface
{
    public const SERVICE = 'ozon-fetcher';
    private const IDLE_TIMEOUT_SECONDS = 2.0;
    private const MAX_DURATION_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ServiceCoverageV1Parser $parser,
        private LoggerInterface $logger,
        #[Autowire('%env(default::OZON_FETCHER_BASE_URL)%')]
        private ?string $baseUrl,
        #[Autowire('%env(default::OZON_FETCHER_API_TOKEN)%')]
        private ?string $token,
    ) {
    }

    public function service(): string
    {
        return self::SERVICE;
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
        $context = ['service' => self::SERVICE, 'method' => 'GET', 'url' => $url, 'traceId' => $traceId];

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

                throw ServiceUnavailableException::status(self::SERVICE, $status);
            }
            $data = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw ContractViolationException::at(ServiceCoverageV1Parser::CONTRACT, '$', 'response is not a JSON object');
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Service coverage request failed.', $context + [
                'durationMs' => $this->elapsedMs($startedAt),
                'exceptionClass' => $e::class,
            ]);

            throw ServiceUnavailableException::transport(self::SERVICE, $e);
        }

        $report = $this->parser->parse($data);
        if (self::SERVICE !== $report->service) {
            throw ContractViolationException::at(ServiceCoverageV1Parser::CONTRACT, '$.service', sprintf('expected %s', self::SERVICE));
        }
        $this->logger->info('Service coverage fetched.', $context + ['rows' => \count($report->rows)]);

        return $report;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
