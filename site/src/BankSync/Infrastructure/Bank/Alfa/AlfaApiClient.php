<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Domain\BankAccess;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class AlfaApiClient
{
    private const BANK_CODE = 'alfa';
    public const ACCOUNTS_PATH = '/api/jp/open-banking/v3.0/aisp-le/accounts';
    public const STATEMENT_PATH = '/api/jp/v1/statement/transactions';
    public const SUMMARY_PATH = '/api/jp/v1/statement/summary';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private AlfaTraceIdRecorder $traceIds,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getAccounts(BankAccess $access): array
    {
        return $this->request($access, 'GET', self::ACCOUNTS_PATH);
    }

    /**
     * @return array<mixed>
     */
    public function getTransactions(BankAccess $access, string $accountNumber, \DateTimeInterface $date, int $page): array
    {
        return $this->request($access, 'GET', self::STATEMENT_PATH, [
            'query' => [
                'accountNumber' => $accountNumber,
                'statementDate' => $date->format('Y-m-d'),
                'page' => $page,
            ],
        ]);
    }

    /**
     * Обороты по счёту за день.
     *
     * @return array<mixed>
     */
    public function getSummary(BankAccess $access, string $accountNumber, \DateTimeInterface $date): array
    {
        return $this->request($access, 'GET', self::SUMMARY_PATH, [
            'query' => [
                'accountNumber' => $accountNumber,
                'statementDate' => $date->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * В лог идёт путь без query: в query номер счёта. Тело ответа не логируется; `X-TraceId`
     * ответа — в лог, поддержка Альфы разбирает обращения по нему.
     *
     * Исключения HttpClient не передаются как previous: Symfony вписывает в их
     * сообщение полный URL с query, и номер счёта ушёл бы в GlitchTip через
     * цепочку исключений. Вместо этого класс исходного исключения — в логе.
     *
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(BankAccess $access, string $method, string $path, array $options = []): array
    {
        $url = rtrim($access->baseUrl, '/').$path;
        $startedAt = microtime(true);
        $context = [
            'method' => $method,
            'path' => $path,
            'bank_connection_id' => $access->connectionId,
        ];

        try {
            $response = $this->httpClient->request($method, $url, array_merge([
                'headers' => [
                    'Authorization' => 'Bearer '.$access->token,
                    'x-fapi-interaction-id' => Uuid::uuid4()->toString(),
                ],
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
        $context['trace_id'] = $this->traceId($response);
        $this->traceIds->record($context['trace_id']);

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

    private function traceId(ResponseInterface $response): ?string
    {
        try {
            return $response->getHeaders(false)['x-traceid'][0] ?? null;
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
