<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Api;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Shared\Infrastructure\Performance\PerformanceProbe;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Infrastructure\Performance\PerformanceStage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Загружает отчёт о реализации товаров Ozon за месяц.
 *
 * POST /v2/finance/realization
 * Запрос: { "month": 2, "year": 2026 }
 *
 * Ограничения API:
 * - Только целый календарный месяц
 * - Данные доступны не ранее 5-8 числа следующего месяца
 * - Позаказная детализация: одна строка = один SKU в одном заказе
 */
final class OzonRealizationFetcher
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const ENDPOINT = '/v2/finance/realization';
    private const ERROR_EXCERPT_LIMIT = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ConnectionApiKeyCodec $connectionApiKeyCodec,
        private readonly PerformanceRecorder $performance = new PerformanceRecorder(),
    ) {
    }

    /**
     * @return array Полный ответ API: result.rows[], result.header_additional и т.д.
     *
     * @throws MarketplaceApiException если API вернул ошибку (лимит, сбой Ozon, ключ, «не готово»); сетевой сбой — TransportExceptionInterface
     */
    public function fetch(MarketplaceConnection $connection, int $year, int $month): array
    {
        $this->validatePeriod($year, $month);

        $this->logger->info('Ozon realization fetch started', [
            'year' => $year,
            'month' => $month,
        ]);

        // Время включает toArray(): разбор JSON от загрузки не отделён (09-m1-diagnostics.md).
        $data = $this->performance->measure(PerformanceStage::ApiFetch, function (PerformanceProbe $probe) use ($connection, $year, $month): array {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::ENDPOINT, [
                'headers' => $this->buildHeaders($connection),
                'json' => [
                    'month' => $month,
                    'year' => $year,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                throw $this->apiFailure($statusCode, $response, $year, $month);
            }

            $data = $response->toArray();
            $probe->rows(is_countable($data['result']['rows'] ?? null) ? count($data['result']['rows']) : 0)
                ->bytes($response->getInfo('size_download'));

            return $data;
        });

        $rows = $data['result']['rows'] ?? [];

        $this->logger->info('Ozon realization fetched', [
            'year' => $year,
            'month' => $month,
            'rows_count' => count($rows),
        ]);

        return $data;
    }

    /**
     * Статус ответа → тип исключения, чтобы вызывающий отличал лимит, сбой Ozon, ключ и «отчёт ещё не готов».
     * В исключение уезжает короткий фрагмент ответа неуспешного запроса (не данные продавца).
     */
    private function apiFailure(int $status, ResponseInterface $response, int $year, int $month): MarketplaceApiException
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $excerpt = mb_substr($response->getContent(false), 0, self::ERROR_EXCERPT_LIMIT);

        return match (true) {
            429 === $status => new MarketplaceRateLimitException($status, $excerpt, $from, $to, $this->retryAfterSeconds($response)),
            401 === $status, 403 === $status => new MarketplaceAuthException('Ozon realization API rejected the API key.', $status, $excerpt, $from, $to),
            $status >= 500 => new MarketplaceTemporaryApiException('Ozon realization API is temporarily unavailable.', $status, $excerpt, $from, $to),
            default => new MarketplaceBadRequestException(sprintf('Ozon realization API returned HTTP %d for %d-%02d', $status, $year, $month), $status, $excerpt, $from, $to),
        };
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        try {
            $value = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    /**
     * Проверяем что запрашиваем не будущий месяц и не слишком старый.
     */
    private function validatePeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(sprintf('Invalid month: %d', $month));
        }

        $now = new \DateTimeImmutable();
        $requestedPeriod = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));

        // Нельзя запросить текущий или будущий месяц — отчёт ещё не сформирован
        if ($requestedPeriod >= $now->modify('first day of this month')) {
            throw new \InvalidArgumentException(sprintf('Cannot fetch realization for current or future month: %d-%02d. Report is available after 5th of the following month.', $year, $month));
        }
    }

    private function buildHeaders(MarketplaceConnection $connection): array
    {
        return [
            'Client-Id' => $connection->getClientId(),
            'Api-Key' => $this->connectionApiKeyCodec->apiKeyFor($connection),
            'Content-Type' => 'application/json',
        ];
    }
}
