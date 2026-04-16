<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Shared\Service\AppLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Загружает отчёт «Взаиморасчёты» (mutual settlement) из Ozon Seller API.
 *
 * Endpoint: POST /v1/finance/mutual-settlement
 * Авторизация: Client-Id + Api-Key (те же, что для transaction/list).
 *
 * Ответ может быть:
 *   - JSON (синхронный режим)
 *   - report_code (асинхронный режим → polling → файл)
 *   - Бинарный файл (CSV/XLSX) — возвращается как binary_content
 */
final readonly class OzonMutualSettlementClient
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const ENDPOINT = '/v1/finance/mutual-settlement';
    private const REPORT_INFO_ENDPOINT = '/v1/report/info';

    private const REQUEST_TIMEOUT = 120;
    private const POLL_MAX_WAIT_SECONDS = 60;
    private const POLL_INITIAL_DELAY_SECONDS = 2;

    /** Content-Type подстроки, которые пробуем парсить как JSON */
    private const JSON_CONTENT_TYPES = ['json'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
        private AppLogger $appLogger,
    ) {
    }

    /**
     * @return array{data: array, records_count: int, response_size: int, binary_content: ?string, content_type: ?string}
     *
     * @throws \RuntimeException если credentials не найдены или API вернул ошибку
     */
    public function fetch(
        string $companyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): array {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::OZON);

        if (null === $credentials) {
            throw new \RuntimeException('Ozon API credentials не найдены для компании.');
        }

        $apiKey = $credentials['api_key'];
        $clientId = $credentials['client_id'] ?? null;

        if ('' === $apiKey || null === $clientId || '' === $clientId) {
            throw new \RuntimeException('Ozon API credentials неполные: отсутствует api_key или client_id.');
        }

        $headers = [
            'Client-Id' => $clientId,
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ];

        $requestBody = [
            'date' => $periodFrom->format('Y-m'),
            'language' => 'DEFAULT',
        ];

        $url = self::BASE_URL . self::ENDPOINT;

        $this->appLogger->info('Ozon MS request', [
            'companyId' => $companyId,
            'url' => $url,
            'body' => $requestBody,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $requestBody,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (\Exception $e) {
            $this->appLogger->error('Ozon MS failed', $e, ['companyId' => $companyId, 'request_body' => $requestBody]);

            throw new \RuntimeException(
                sprintf('Ozon mutual settlement: ошибка соединения: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $responseBody = $response->getContent(false);
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        $responseSize = strlen($responseBody);

        $this->appLogger->info('Ozon MS response headers', [
            'companyId' => $companyId,
            'status' => $statusCode,
            'content_type' => $contentType,
            'content_length' => $responseSize,
            'body_preview' => substr($responseBody, 0, 200),
        ]);

        if ($statusCode !== 200) {
            $exception = new \RuntimeException(sprintf(
                'Ozon mutual settlement API вернул HTTP %d: %s',
                $statusCode,
                mb_substr($responseBody, 0, 200),
            ));
            $this->appLogger->error('Ozon MS failed', $exception, ['companyId' => $companyId, 'request_body' => $requestBody]);

            throw $exception;
        }

        // Не-JSON ответ (XLSX, CSV и т.д.) — возвращаем бинарное содержимое
        if (!$this->isJsonContentType($contentType)) {
            $this->appLogger->info('Ozon MS: бинарный ответ', [
                'companyId' => $companyId,
                'content_type' => $contentType,
                'size_bytes' => $responseSize,
            ]);

            return [
                'data' => [],
                'records_count' => 0,
                'response_size' => $responseSize,
                'binary_content' => $responseBody,
                'content_type' => $contentType,
            ];
        }

        $data = json_decode($responseBody, true);

        if (!is_array($data)) {
            // JSON Content-Type, но невалидный JSON — возвращаем как бинарный
            return [
                'data' => [],
                'records_count' => 0,
                'response_size' => $responseSize,
                'binary_content' => $responseBody,
                'content_type' => $contentType,
            ];
        }

        // JSON-ответ — проверяем асинхронный режим
        $reportCode = $data['report_code'] ?? $data['result']['code'] ?? $data['code'] ?? null;
        if (null !== $reportCode && '' !== (string) $reportCode) {
            $this->appLogger->info('Ozon MS: асинхронный режим, polling', [
                'companyId' => $companyId,
                'reportCode' => $reportCode,
            ]);

            $pollResult = $this->pollReport($headers, (string) $reportCode, $companyId);

            // Если polling вернул бинарный файл
            if (null !== $pollResult['binary_content']) {
                return [
                    'data' => [],
                    'records_count' => 0,
                    'response_size' => strlen($pollResult['binary_content']),
                    'binary_content' => $pollResult['binary_content'],
                    'content_type' => $pollResult['content_type'],
                ];
            }

            $data = $pollResult['data'];
        }

        $recordsCount = $this->countRecords($data);

        $this->appLogger->info('Ozon MS: загрузка завершена', [
            'companyId' => $companyId,
            'recordsCount' => $recordsCount,
            'responseSize' => $responseSize,
        ]);

        return [
            'data' => $data,
            'records_count' => $recordsCount,
            'response_size' => $responseSize,
            'binary_content' => null,
            'content_type' => null,
        ];
    }

    /**
     * Polling отчёта с exponential backoff.
     *
     * @param array<string, string> $headers
     *
     * @return array{data: array, binary_content: ?string, content_type: ?string}
     */
    private function pollReport(
        array $headers,
        string $reportCode,
        string $companyId,
    ): array {
        $delay = self::POLL_INITIAL_DELAY_SECONDS;
        $totalWaited = 0;

        while ($totalWaited < self::POLL_MAX_WAIT_SECONDS) {
            sleep($delay);
            $totalWaited += $delay;

            $response = $this->httpClient->request('POST', self::BASE_URL . self::REPORT_INFO_ENDPOINT, [
                'headers' => $headers,
                'json' => ['code' => $reportCode],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf(
                    'Ozon report/info вернул HTTP %d для report_code=%s',
                    $statusCode,
                    $reportCode,
                ));
            }

            $data = $response->toArray();

            // /v1/report/info возвращает status и file внутри result,
            // но на всякий случай проверяем и верхний уровень.
            $report = $data['result'] ?? $data;
            $status = (string) ($report['status'] ?? $data['status'] ?? '');

            if ('success' === $status || 'completed' === $status) {
                $fileUrl = (string) ($report['file'] ?? $data['file'] ?? '');
                if ('' !== $fileUrl) {
                    return $this->downloadReport($fileUrl, $companyId);
                }

                return [
                    'data' => $data,
                    'binary_content' => null,
                    'content_type' => null,
                ];
            }

            if ('failed' === $status || 'error' === $status) {
                throw new \RuntimeException(sprintf(
                    'Ozon отчёт %s завершился с ошибкой: %s',
                    $reportCode,
                    (string) ($report['error'] ?? $data['error'] ?? 'unknown'),
                ));
            }

            // Exponential backoff: 2, 4, 8, 16...
            $delay = min($delay * 2, self::POLL_MAX_WAIT_SECONDS - $totalWaited);
            if ($delay <= 0) {
                break;
            }
        }

        throw new \RuntimeException(sprintf(
            'Ozon отчёт %s не готов за %d секунд',
            $reportCode,
            self::POLL_MAX_WAIT_SECONDS,
        ));
    }

    /**
     * Скачивание файла отчёта.
     *
     * Не передаём API credentials — fileUrl обычно pre-signed ссылка
     * на внешнее хранилище (Yandex Cloud / AWS), авторизация не нужна.
     *
     * Возвращает массив с ключами 'data', 'binary_content', 'content_type'.
     * Если файл — JSON, data содержит распарсенные данные, binary_content = null.
     * Если файл бинарный (XLSX и т.д.), data = [], binary_content = raw bytes.
     *
     * @return array{data: array, binary_content: ?string, content_type: string}
     */
    private function downloadReport(
        string $fileUrl,
        string $companyId,
    ): array {
        $response = $this->httpClient->request('GET', $fileUrl, [
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $content = $response->getContent();
        $contentType = $response->getHeaders()['content-type'][0] ?? '';
        $contentSize = strlen($content);

        $this->appLogger->info('Ozon MS: скачан файл отчёта', [
            'companyId' => $companyId,
            'content_type' => $contentType,
            'size_bytes' => $contentSize,
        ]);

        // Если ответ — JSON, пробуем распарсить
        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                return [
                    'data' => $decoded,
                    'binary_content' => null,
                    'content_type' => $contentType,
                ];
            }
        }

        // Не-JSON или невалидный JSON — возвращаем бинарное содержимое
        return [
            'data' => [],
            'binary_content' => $content,
            'content_type' => $contentType,
        ];
    }

    /**
     * Подсчитывает количество записей в ответе.
     */
    private function countRecords(array $data): int
    {
        // Бинарные/текстовые обёртки — записей нет
        if (isset($data['_binary']) || isset($data['_text'])) {
            return 0;
        }

        // result.rows или result
        if (isset($data['result']) && is_array($data['result'])) {
            if (isset($data['result']['rows']) && is_array($data['result']['rows'])) {
                return count($data['result']['rows']);
            }

            return count($data['result']);
        }

        // rows на верхнем уровне
        if (isset($data['rows']) && is_array($data['rows'])) {
            return count($data['rows']);
        }

        return 0;
    }

    private function isJsonContentType(string $contentType): bool
    {
        if ('' === $contentType) {
            // Если Content-Type не указан — пробуем как JSON
            return true;
        }

        foreach (self::JSON_CONTENT_TYPES as $jsonType) {
            if (str_contains($contentType, $jsonType)) {
                return true;
            }
        }

        return false;
    }
}
