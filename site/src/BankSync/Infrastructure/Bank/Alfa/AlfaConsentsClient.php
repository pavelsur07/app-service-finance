<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Domain\Consent\BankConsent;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\OAuth\OAuthTokenEndpointClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Согласия пользователей нашего приложения у Альфы: `GET /oidc/client-consents` по токену
 * Client Credentials (уровень приложения, без refresh), все страницы до возврата. Пустая или
 * неполная страница раньше `totalCount` — отказ: решения принимаются только по полному списку.
 *
 * В лог — путь, страница, статус, время, `X-TraceId`; ни `sub`, ни хэша организации, ни
 * тела ответа.
 */
final readonly class AlfaConsentsClient
{
    /** Scope токена Client Credentials для client-consents в документации не назван — Stage 7. */
    public const CLIENT_CREDENTIALS_SCOPE = 'openid';
    public const PAGE_SIZE = 1000;
    /** Страховка от бесконечной пагинации: 100 000 согласий. */
    private const MAX_PAGES = 100;
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        #[Autowire(service: 'bank_sync.alfa.token_endpoint_client')]
        private OAuthTokenEndpointClient $tokenEndpoint,
        #[Autowire(service: 'bank_sync.alfa.http_client')]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<BankConsent>
     *
     * @throws BankApiUnavailableException сеть, 408, 429, 5xx — повторить завтра
     * @throws BankApiRequestFailedException отказ в токене приложения, прочие 4xx, нечитаемый ответ
     * @throws BankChannelNotConfiguredException канал до банка (mTLS) не настроен
     */
    public function fetchAll(AlfaIdEnvironment $environment): array
    {
        $accessToken = $this->clientCredentialsToken($environment);

        $consents = [];
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $payload = $this->page($environment, $accessToken, $page * self::PAGE_SIZE);
            $items = $payload['items'] ?? null;
            $total = $payload['pagination']['totalCount'] ?? null;
            if (!\is_array($items) || !array_is_list($items) || !(\is_int($total) || \is_float($total))) {
                $this->logger->warning('Alfa client consents: unreadable response', ['page' => $page]);

                throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, null);
            }
            foreach ($items as $item) {
                $consents[] = $this->consent($item);
            }
            if (\count($consents) >= (int) $total) {
                return $consents;
            }
            // Неполный список хуже ошибки: пропущенное действующее согласие погасит подключение.
            if (\count($items) < self::PAGE_SIZE) {
                $this->logger->warning('Alfa client consents: page ended before totalCount', [
                    'page' => $page,
                    'items' => \count($items),
                    'collected' => \count($consents),
                    'total' => (int) $total,
                ]);

                throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, null);
            }
        }

        $this->logger->warning('Alfa client consents: page limit reached', ['pages' => self::MAX_PAGES]);

        throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, null);
    }

    private function clientCredentialsToken(AlfaIdEnvironment $environment): string
    {
        try {
            return $this->tokenEndpoint->requestTokens(AlfaIdConfig::BANK_CODE, $environment->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $environment->clientId,
                'client_secret' => $environment->clientSecret,
                'scope' => self::CLIENT_CREDENTIALS_SCOPE,
            ])->tokens->accessToken;
        } catch (BankAuthorizationRejectedException $exception) {
            // Клиента тут нет: отказ в токене приложения — наша настройка.
            throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, $exception->getStatusCode());
        }
    }

    /**
     * @return array<mixed>
     */
    private function page(AlfaIdEnvironment $environment, #[\SensitiveParameter] string $accessToken, int $offset): array
    {
        $startedAt = microtime(true);
        $context = ['method' => 'GET', 'url' => $environment->consentsUrl(), 'offset' => $offset];

        try {
            $response = $this->httpClient->request('GET', $environment->consentsUrl(), [
                'query' => ['clientId' => $environment->clientId, 'limit' => self::PAGE_SIZE, 'offset' => $offset],
                'headers' => ['Authorization' => 'Bearer '.$accessToken, 'Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $context['status_code'] = $statusCode;
            $context['trace_id'] = $response->getHeaders(false)['x-traceid'][0] ?? 'none';
            $payload = 200 === $statusCode ? $response->toArray(false) : null;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Alfa client consents request failed: transport error', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new BankApiUnavailableException(AlfaIdConfig::BANK_CODE, null);
        } catch (DecodingExceptionInterface) {
            $payload = null;
        }
        $context['duration_ms'] = $this->elapsedMs($startedAt);

        if (200 !== $statusCode) {
            if (\in_array($statusCode, [408, 429], true) || $statusCode >= 500) {
                $this->logger->warning('Alfa client consents temporarily unavailable', $context);

                throw new BankApiUnavailableException(AlfaIdConfig::BANK_CODE, $statusCode);
            }
            $this->logger->warning('Alfa client consents request rejected', $context);

            throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, $statusCode);
        }
        if (null === $payload) {
            $this->logger->warning('Alfa client consents: unreadable response', $context);

            throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, null);
        }

        $this->logger->info('Alfa client consents request', $context);

        return $payload;
    }

    private function consent(mixed $item): BankConsent
    {
        $subject = \is_array($item) ? ($item['sub'] ?? null) : null;
        $isActive = \is_array($item) ? ($item['isActive'] ?? null) : null;
        $expireDate = \is_array($item) ? ($item['expireDate'] ?? null) : null;
        $expiresOn = \is_string($expireDate) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $expireDate) : false;
        if (!\is_string($subject) || '' === $subject || !\is_bool($isActive) || false === $expiresOn) {
            $this->logger->warning('Alfa client consents: unreadable consent item');

            throw new BankApiRequestFailedException(AlfaIdConfig::BANK_CODE, null);
        }

        return new BankConsent($subject, $isActive, $expiresOn);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
