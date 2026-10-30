<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент API задач ai-service. Токен направления «Symfony → сервис»
 * (`AI_SERVICE_API_TOKEN`, service-gateway.md §2.9). Повторов внутри вызова нет:
 * постановку повторяет Messenger (тот же `Idempotency-Key`), забор — следующий запуск.
 * В лог — метод, URL, статус, время; тело запроса и ответа не пишется.
 */
final readonly class AiServiceClient implements AiServiceClientInterface
{
    public const SERVICE = 'ai-service';
    private const IDLE_TIMEOUT_SECONDS = 3.0;
    private const MAX_DURATION_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AiTaskV1Parser $parser,
        private LoggerInterface $logger,
        #[Autowire('%env(default::AI_SERVICE_BASE_URL)%')]
        private ?string $baseUrl,
        #[Autowire('%env(default::AI_SERVICE_API_TOKEN)%')]
        private ?string $token,
    ) {
    }

    public function submitTask(array $request): AiTaskAccepted
    {
        $key = $request['idempotency_key'] ?? null;
        $traceId = $request['trace_id'] ?? null;
        if (!\is_string($key) || !\is_string($traceId)) {
            throw new \InvalidArgumentException('task request must carry idempotency_key and trace_id');
        }

        $body = $this->request('POST', '/internal/tasks', $traceId, [
            'json' => $request,
            'headers' => ['Idempotency-Key' => $key],
        ], 202);

        return $this->parser->parseAccepted($body);
    }

    public function fetchResults(?string $after, int $limit): AiResultsPage
    {
        $query = ['status' => 'done', 'limit' => $limit];
        if (null !== $after) {
            $query['after'] = $after;
        }
        $body = $this->request('GET', '/internal/tasks', Uuid::uuid7()->toString(), ['query' => $query], 200);

        return $this->parser->parseResultsPage($body);
    }

    public function ack(array $taskIds): int
    {
        $body = $this->request('POST', '/internal/tasks/ack', Uuid::uuid7()->toString(), [
            'json' => ['task_ids' => $taskIds],
        ], 200);
        if (!$body instanceof \stdClass || !isset($body->acked) || !\is_int($body->acked) || 1 !== \count(get_object_vars($body))) {
            throw ContractViolationException::at(AiTaskV1Parser::CONTRACT, '$', 'ack_response must be {"acked": int}');
        }

        return $body->acked;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, string $traceId, array $options, int $expectedStatus): mixed
    {
        if ('' === (string) $this->baseUrl || '' === (string) $this->token) {
            throw ServiceUnavailableException::notConfigured(self::SERVICE);
        }
        $url = rtrim((string) $this->baseUrl, '/').$path;
        $startedAt = microtime(true);
        $context = ['service' => self::SERVICE, 'method' => $method, 'url' => $url, 'traceId' => $traceId];

        $headers = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'X-Trace-Id' => $traceId,
        ];
        try {
            $response = $this->httpClient->request($method, $url, [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ] + ['headers' => $headers] + $options);
            $status = $response->getStatusCode();
            $context += ['status' => $status, 'durationMs' => $this->elapsedMs($startedAt)];
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('ai-service request failed.', $context + [
                'durationMs' => $this->elapsedMs($startedAt),
                'exceptionClass' => $e::class,
            ]);

            throw ServiceUnavailableException::transport(self::SERVICE, $e);
        }

        if ($status !== $expectedStatus) {
            $this->logger->warning('ai-service request failed.', $context);
            // 401 — рассинхрон токена при деплое, 5xx — сбой сервиса: повторяемо.
            if (401 === $status || $status >= 500) {
                throw ServiceUnavailableException::status(self::SERVICE, $status);
            }

            throw AiTaskRejectedException::status($status, $this->errorCode($content));
        }
        $this->logger->info('ai-service request done.', $context);

        try {
            return json_decode($content, false, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ContractViolationException::at(AiTaskV1Parser::CONTRACT, '$', 'response is not JSON');
        }
    }

    /**
     * Стабильный код ошибки сервиса (`{"error":{"code"}}`) без текста сообщения.
     */
    private function errorCode(string $content): string
    {
        try {
            $data = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unknown';
        }
        $code = \is_array($data) && \is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;

        return \is_string($code) && 1 === preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) ? $code : 'unknown';
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
