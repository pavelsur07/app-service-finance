<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OzonSellerCredentialValidator implements OzonSellerCredentialValidatorInterface
{
    private const URL = 'https://api-seller.ozon.ru/v4/product/info/limit';
    private const REQUEST_TIMEOUT = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function validate(?string $clientId, string $apiKey): OzonCredentialValidationResult
    {
        $clientId = trim((string) $clientId);
        $apiKey = trim($apiKey);

        if ('' === $clientId || '' === $apiKey) {
            return new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::INVALID_CREDENTIALS,
                'Укажите Client-Id и Api-Key Ozon Seller API.',
            );
        }

        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'headers' => [
                    'Client-Id' => $clientId,
                    'Api-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => new \stdClass(),
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Ozon Seller credential validation transport error.', [
                'error' => $e->getMessage(),
            ]);

            return new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::TEMPORARY_ERROR,
                'Ozon Seller API временно недоступен. Повторите проверку позже.',
            );
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return OzonCredentialValidationResult::valid();
        }

        $bodyExcerpt = mb_substr(trim($body), 0, 300);

        return match (true) {
            401 === $statusCode || 403 === $statusCode => new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::INVALID_CREDENTIALS,
                'Ozon отклонил Client-Id или Api-Key. Проверьте ключ Seller API.',
                $statusCode,
            ),
            404 === $statusCode && $this->looksLikeInvalidCredentialResponse($body) => new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::INVALID_CREDENTIALS,
                'Ozon не нашёл активный Seller API ключ для указанных данных.',
                $statusCode,
            ),
            429 === $statusCode => new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::RATE_LIMITED,
                'Ozon временно ограничил количество запросов. Повторите проверку позже.',
                $statusCode,
            ),
            $statusCode >= 500 => new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::TEMPORARY_ERROR,
                'Ozon Seller API вернул временную ошибку. Повторите проверку позже.',
                $statusCode,
            ),
            default => new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::UNEXPECTED_ERROR,
                sprintf(
                    'Неожиданный ответ Ozon Seller API при проверке ключа: HTTP %d%s',
                    $statusCode,
                    '' !== $bodyExcerpt ? ', '.$bodyExcerpt : '',
                ),
                $statusCode,
            ),
        };
    }

    private function looksLikeInvalidCredentialResponse(string $body): bool
    {
        $normalized = mb_strtolower($body);

        return str_contains($normalized, 'api')
            || str_contains($normalized, 'key')
            || str_contains($normalized, 'client')
            || str_contains($normalized, 'credential')
            || str_contains($normalized, 'invalid')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'forbidden');
    }
}
