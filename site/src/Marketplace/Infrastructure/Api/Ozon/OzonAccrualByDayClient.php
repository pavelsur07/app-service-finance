<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Shared\Service\AppLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Загружает начисления Ozon за день.
 *
 * Endpoint: POST /v1/finance/accrual/by-day
 * Пришёл на замену /v3/finance/transaction/list, снятому Ozon 09.09.2026.
 *
 * Запрос: {"date": "YYYY-MM-DD"} плюс "last_id" на последующих страницах.
 * Ответ:  {"accruals": [...], "last_id": "..."} — пагинация продолжается,
 * пока last_id непустой. Форма подтверждена выгрузкой за июнь 2026.
 */
final readonly class OzonAccrualByDayClient implements OzonAccrualByDayClientInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const ENDPOINT = '/v1/finance/accrual/by-day';
    private const REQUEST_TIMEOUT = 120;

    /** Потолок фрагмента конверта ошибки Ozon, переносимого в исключение. */
    private const ERROR_EXCERPT_LIMIT = 500;

    /**
     * Защитный предел числа страниц. В выгрузке за июнь ни один день не занял
     * больше одной страницы, поэтому сотня — заведомый запас. Достижение
     * предела с непустым курсором — не повод молча обрезать финансовый день,
     * а повод упасть.
     */
    private const MAX_PAGES = 100;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
        private AppLogger $appLogger,
    ) {
    }

    /**
     * Все начисления за один день.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MarketplaceRateLimitException лимит запросов, вызывающему следует повторить
     * @throws \RuntimeException отсутствуют credentials либо неустранимая ошибка API
     */
    public function fetchDay(string $companyId, \DateTimeImmutable $date): array
    {
        $headers = $this->buildHeaders($companyId);
        $day = $date->format('Y-m-d');

        $accruals = [];
        $lastId = '';
        $page = 0;
        $seenCursors = [];

        do {
            ++$page;

            $json = ['date' => $day];
            if ('' !== $lastId) {
                $json['last_id'] = $lastId;
            }

            $payload = $this->request($headers, $json, $companyId, $day, $page);

            // Отсутствующий или не-массивный `accruals` — это не «день без
            // начислений», а неожиданная форма ответа. Принять её за пустой день
            // значит записать финансовый день как успешно загруженный.
            if (!isset($payload['accruals']) || !is_array($payload['accruals'])) {
                throw new MarketplaceInvalidApiResponseException('Ozon accrual by-day response has no "accruals" array.', 200, $this->excerpt($payload), $day, $day);
            }

            foreach ($payload['accruals'] as $row) {
                if (is_array($row)) {
                    $accruals[] = $row;
                }
            }

            $lastId = is_string($payload['last_id'] ?? null) ? trim($payload['last_id']) : '';

            if ('' !== $lastId && isset($seenCursors[$lastId])) {
                throw new MarketplaceInvalidApiResponseException(sprintf('Ozon accrual by-day repeated pagination cursor on page %d.', $page), 200, $this->excerpt($payload), $day, $day);
            }
            $seenCursors[$lastId] = true;

            if ('' !== $lastId && $page >= self::MAX_PAGES) {
                throw new MarketplaceInvalidApiResponseException(sprintf('Ozon accrual by-day exceeded %d pages with a non-empty cursor.', self::MAX_PAGES), 200, $this->excerpt($payload), $day, $day);
            }
        } while ('' !== $lastId);

        return $accruals;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function request(array $headers, array $json, string $companyId, string $day, int $page): array
    {
        $response = $this->httpClient->request('POST', self::BASE_URL.self::ENDPOINT, [
            'headers' => $headers,
            'json' => $json,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $status = $response->getStatusCode();

        if (200 !== $status) {
            // Конверт ошибки Ozon переносится в исключение осознанно: именно его
            // отсутствие не дало опознать снятие v3 в ночь на 09.09.2026. Это
            // ответ неуспешного запроса, а не данные продавца.
            $excerpt = mb_substr($response->getContent(false), 0, self::ERROR_EXCERPT_LIMIT);

            throw match (true) {
                429 === $status => new MarketplaceRateLimitException(
                    $status,
                    $excerpt,
                    $day,
                    $day,
                    $this->retryAfterSeconds($response),
                ),
                401 === $status, 403 === $status => new MarketplaceAuthException(
                    'Ozon accrual by-day rejected the API key.',
                    $status,
                    $excerpt,
                    $day,
                    $day,
                ),
                $status >= 500 => new MarketplaceTemporaryApiException(
                    'Ozon accrual by-day is temporarily unavailable.',
                    $status,
                    $excerpt,
                    $day,
                    $day,
                ),
                default => new MarketplaceBadRequestException(
                    'Ozon accrual by-day rejected the request.',
                    $status,
                    $excerpt,
                    $day,
                    $day,
                ),
            };
        }

        $payload = $response->toArray(false);

        $this->appLogger->info('Ozon accrual by-day page fetched', [
            'company_id' => $companyId,
            'date' => $day,
            'page' => $page,
            'rows' => is_array($payload['accruals'] ?? null) ? count($payload['accruals']) : 0,
        ]);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function excerpt(array $payload): string
    {
        return mb_substr(json_encode($payload, \JSON_UNESCAPED_UNICODE) ?: '', 0, self::ERROR_EXCERPT_LIMIT);
    }

    /**
     * Ozon присылает Retry-After на посекундном лимите. Передаём его дальше:
     * вызывающий решает, когда повторить, а не гадает.
     */
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
     * @return array<string, string>
     */
    private function buildHeaders(string $companyId): array
    {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::OZON);

        if (null === $credentials) {
            throw new \RuntimeException('Ozon API credentials не найдены для компании.');
        }

        $apiKey = $credentials['api_key'];
        $clientId = (string) ($credentials['client_id'] ?? '');

        if ('' === $apiKey || '' === $clientId) {
            throw new \RuntimeException('Ozon API credentials неполные: отсутствует api_key или client_id.');
        }

        return [
            'Client-Id' => $clientId,
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ];
    }
}
