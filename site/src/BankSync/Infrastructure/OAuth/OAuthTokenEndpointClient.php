<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Token endpoint OAuth 2.0 (RFC 6749 §4.1.3, §6): POST form-urlencoded, ответ —
 * `access_token`, `expires_in`, необязательный `refresh_token`. Общий для банков;
 * адрес и поля формы (в т.ч. `client_secret`) передаёт провайдер банка.
 *
 * В лог — метод, адрес без query, статус, время и код ошибки OAuth. Ни тело запроса
 * (код, verifier, refresh, secret), ни тело ответа (токены) не логируются; исключения
 * HttpClient не цепляются как previous.
 */
final readonly class OAuthTokenEndpointClient
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $form
     *
     * @throws BankAuthorizationRejectedException 400/401: код или refresh отвергнут
     * @throws BankApiUnavailableException сеть, 408, 429, 5xx
     * @throws BankApiRequestFailedException прочие 4xx или нечитаемый ответ
     */
    public function requestTokens(string $bankCode, string $tokenUrl, #[\SensitiveParameter] array $form): OAuthTokens
    {
        $startedAt = microtime(true);
        $context = [
            'bank_code' => $bankCode,
            'method' => 'POST',
            'url' => strtok($tokenUrl, '?'),
            'grant_type' => $form['grant_type'] ?? 'none',
        ];

        try {
            $response = $this->httpClient->request('POST', $tokenUrl, [
                'body' => $form,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Bank OAuth token request failed: transport error', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new BankApiUnavailableException($bankCode, null);
        } catch (DecodingExceptionInterface) {
            $payload = null;
        }

        $context['status_code'] = $statusCode;
        $context['duration_ms'] = $this->elapsedMs($startedAt);
        $oauthError = \is_array($payload) && \is_string($payload['error'] ?? null) ? $payload['error'] : null;

        if (200 !== $statusCode) {
            if (\in_array($statusCode, [408, 429], true) || $statusCode >= 500) {
                $this->logger->warning('Bank OAuth token endpoint temporarily unavailable', $context);

                throw new BankApiUnavailableException($bankCode, $statusCode);
            }

            $rejected = new BankAuthorizationRejectedException($bankCode, $statusCode, $oauthError);
            $context['oauth_error'] = $rejected->getOauthError() ?? 'none';
            if (\in_array($statusCode, [400, 401], true)) {
                $this->logger->info('Bank OAuth token request rejected', $context);

                throw $rejected;
            }

            $this->logger->warning('Bank OAuth token request failed', $context);

            throw new BankApiRequestFailedException($bankCode, $statusCode);
        }

        $tokens = \is_array($payload) ? $this->tokens($payload) : null;
        if (null === $tokens) {
            $this->logger->warning('Bank OAuth token endpoint returned an unreadable response', $context);

            throw new BankApiRequestFailedException($bankCode, null);
        }

        $this->logger->info('Bank OAuth token request', $context);

        return $tokens;
    }

    /**
     * @param array<mixed> $payload
     */
    private function tokens(array $payload): ?OAuthTokens
    {
        $access = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        $refresh = $payload['refresh_token'] ?? null;
        if (!\is_string($access) || '' === $access || !\is_int($expiresIn) || $expiresIn <= 0) {
            return null;
        }
        if (null !== $refresh && (!\is_string($refresh) || '' === $refresh)) {
            return null;
        }

        return new OAuthTokens($access, $refresh, $this->clock->now()->modify(sprintf('+%d seconds', $expiresIn)));
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
