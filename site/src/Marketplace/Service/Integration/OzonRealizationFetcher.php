<?php

declare(strict_types=1);

namespace App\Marketplace\Service\Integration;

use App\Marketplace\Entity\MarketplaceConnection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array Полный ответ API: result.rows[], result.header_additional и т.д.
     *
     * @throws \RuntimeException если API вернул ошибку
     */
    public function fetch(MarketplaceConnection $connection, int $year, int $month): array
    {
        $this->validatePeriod($year, $month);

        $this->logger->info('Ozon realization fetch started', [
            'year'  => $year,
            'month' => $month,
        ]);

        $response = $this->httpClient->request('POST', self::BASE_URL . self::ENDPOINT, [
            'headers' => $this->buildHeaders($connection),
            'json'    => [
                'month' => $month,
                'year'  => $year,
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'Ozon realization API returned HTTP %d for %d-%02d',
                $statusCode,
                $year,
                $month,
            ));
        }

        $data = $response->toArray();

        $rows = $data['result']['rows'] ?? [];

        $this->logger->info('Ozon realization fetched', [
            'year'       => $year,
            'month'      => $month,
            'rows_count' => count($rows),
        ]);

        return $data;
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
            throw new \InvalidArgumentException(sprintf(
                'Cannot fetch realization for current or future month: %d-%02d. '
                . 'Report is available after 5th of the following month.',
                $year,
                $month,
            ));
        }
    }

    private function buildHeaders(MarketplaceConnection $connection): array
    {
        return [
            'Client-Id'    => $connection->getClientId(),
            'Api-Key'      => $connection->getApiKey(),
            'Content-Type' => 'application/json',
        ];
    }
}
