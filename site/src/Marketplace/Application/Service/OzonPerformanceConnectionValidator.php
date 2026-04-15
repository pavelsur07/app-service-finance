<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Exception\OzonPerformanceValidationException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Синхронная проверка credentials Ozon Performance API.
 *
 * Используется при сохранении MarketplaceConnection (connection_type = PERFORMANCE):
 * если client_id / client_secret невалидны — OAuth-токен получить не удастся,
 * и сохранять подключение нет смысла.
 */
final readonly class OzonPerformanceConnectionValidator
{
    private const TOKEN_URL = 'https://api-performance.ozon.ru/api/client/token';
    private const REQUEST_TIMEOUT = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Проверить credentials Ozon Performance API, запросив OAuth-токен.
     *
     * @throws OzonPerformanceValidationException
     *   ::invalidCredentials — API вернул 401/403 (client_id / client_secret не подходят);
     *   ::apiUnavailable     — таймаут, сеть или неожиданный HTTP-ответ.
     */
    public function validate(string $clientId, string $clientSecret): void
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw OzonPerformanceValidationException::apiUnavailable(
                'сеть недоступна или превышен таймаут',
                $e,
            );
        }

        if (401 === $statusCode || 403 === $statusCode) {
            throw OzonPerformanceValidationException::invalidCredentials();
        }

        if (200 !== $statusCode) {
            throw OzonPerformanceValidationException::apiUnavailable(
                sprintf('неожиданный HTTP-статус %d', $statusCode),
            );
        }

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw OzonPerformanceValidationException::apiUnavailable(
                'некорректный формат ответа',
                $e,
            );
        }

        if (!isset($data['access_token']) || !is_string($data['access_token']) || '' === $data['access_token']) {
            throw OzonPerformanceValidationException::apiUnavailable('ответ API не содержит access_token');
        }
    }
}
