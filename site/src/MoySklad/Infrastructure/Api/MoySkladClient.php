<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Api;

use App\MoySklad\Application\DTO\ConnectionCheckResult;
use App\MoySklad\Enum\ConnectionCheckStatus;
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
        if ('' === $token || 1 === preg_match('/[\x00-\x20\x7f]/', $token)) {
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
                'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip'],
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
}
