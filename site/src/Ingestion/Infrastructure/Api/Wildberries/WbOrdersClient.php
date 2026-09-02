<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

use App\Ingestion\Application\Source\Wildberries\WbOrderDateParser;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент заказных API Wildberries.
 *
 * Два разных хоста за одним классом: у marketplace-api и statistics-api общий
 * ключ и общая обработка ошибок, а различаются только путь и форма ответа.
 *
 * Собственного ограничителя частоты здесь нет намеренно. У statistics-api
 * лимит порядка одного запроса в минуту, но коннектор делает ровно один вызов
 * за pull, а 429 превращается в {@see ConnectorRateLimitedException} с
 * retryAfterSeconds — отложенный ре-диспатч уже реализован в
 * RunSyncChunkHandler. Заводить второй ограничитель рядом с финансовым значило
 * бы завести второе место, где живёт одно и то же знание о лимитах.
 */
final readonly class WbOrdersClient implements WbOrdersClientInterface
{
    public const MARKETPLACE_PAGE_LIMIT = 1000;

    /** Максимум номеров заказов в одном запросе статусов (ограничение WB). */
    public const STATUS_BATCH_SIZE = 1000;

    private const MARKETPLACE_BASE_URL = 'https://marketplace-api.wildberries.ru';
    private const STATISTICS_BASE_URL = 'https://statistics-api.wildberries.ru';
    private const ORDERS_ENDPOINT = '/api/v3/orders';
    private const ORDERS_STATUS_ENDPOINT = '/api/v3/orders/status';
    private const STATISTICS_ORDERS_ENDPOINT = '/api/v1/supplier/orders';
    private const DEFAULT_RETRY_AFTER_SECONDS = 70;
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        private HttpClientInterface $httpClient,
        private WbCredentialProviderInterface $credentialProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchMarketplaceOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
        int $limit,
        int $next,
    ): WbOrdersPage {
        if ($limit < 1 || $limit > self::MARKETPLACE_PAGE_LIMIT) {
            throw new \InvalidArgumentException(sprintf('WB orders limit must be between 1 and %d.', self::MARKETPLACE_PAGE_LIMIT));
        }
        if ($next < 0) {
            throw new \InvalidArgumentException('WB orders next cursor cannot be negative.');
        }

        $decoded = $this->request(
            'GET',
            self::MARKETPLACE_BASE_URL.self::ORDERS_ENDPOINT,
            $companyId,
            $connectionRef,
            self::ORDERS_ENDPOINT,
            [
                'query' => [
                    // dateFrom здесь — unix-время, и часовых поясов у него нет.
                    'dateFrom' => $since->getTimestamp(),
                    'limit' => $limit,
                    'next' => $next,
                ],
            ],
        );

        $rows = $this->listOfObjects($decoded['data']['orders'] ?? null, $decoded['shape']->orders ?? null, self::ORDERS_ENDPOINT, 'orders');
        $nextToken = $decoded['data']['next'] ?? null;
        if (!is_int($nextToken)) {
            throw new MalformedConnectorResponseException(sprintf('WB %s returned a non-integer next cursor.', self::ORDERS_ENDPOINT));
        }

        // Признак «есть ещё» у WB — непустая страница, а не отдельное поле:
        // документированного флага у эндпоинта нет.
        return new WbOrdersPage(
            rows: $rows,
            hasMore: [] !== $rows,
            nextToken: $nextToken,
            metadata: ['endpoint' => self::ORDERS_ENDPOINT, 'next' => $next, 'limit' => $limit],
        );
    }

    public function fetchMarketplaceStatuses(string $companyId, string $connectionRef, array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }
        if (count($orderIds) > self::STATUS_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf('WB order statuses accept at most %d ids per request.', self::STATUS_BATCH_SIZE));
        }

        $decoded = $this->request(
            'POST',
            self::MARKETPLACE_BASE_URL.self::ORDERS_STATUS_ENDPOINT,
            $companyId,
            $connectionRef,
            self::ORDERS_STATUS_ENDPOINT,
            ['json' => ['orders' => $orderIds]],
        );

        $rows = $this->listOfObjects($decoded['data']['orders'] ?? null, $decoded['shape']->orders ?? null, self::ORDERS_STATUS_ENDPOINT, 'orders');

        $indexed = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_int($id)) {
                throw new MalformedConnectorResponseException(sprintf('WB %s returned a status row without an integer id.', self::ORDERS_STATUS_ENDPOINT));
            }

            // Обе оси обязательны.
            //
            // Строка без них проходила бы дальше и становилась НАБЛЮДЕНИЕМ
            // статуса с пустыми осями: заказ получал бы UNKNOWN, ложное
            // событие журнала и статусную отметку, закрывающую дорогу более
            // старому, но настоящему статусу. Повреждённый ответ обязан
            // приводить к повтору, а не к записи выдуманного состояния.
            if (!is_string($row['supplierStatus'] ?? null) || !is_string($row['wbStatus'] ?? null)) {
                throw new MalformedConnectorResponseException(sprintf('WB %s returned a status row without both status axes.', self::ORDERS_STATUS_ENDPOINT));
            }

            if (array_key_exists('isCancellable', $row) && !is_bool($row['isCancellable'])) {
                throw new MalformedConnectorResponseException(sprintf('WB %s returned a non-boolean isCancellable.', self::ORDERS_STATUS_ENDPOINT));
            }

            $indexed[$id] = $row;
        }

        return $indexed;
    }

    public function fetchStatisticsOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
    ): WbOrdersPage {
        // Зона и формат живут в одном месте с разбором ответа: statistics-api
        // работает в московском времени, и отправленный UTC-момент сместил бы
        // окно на три часа.
        $dateFrom = WbOrderDateParser::formatStatisticsDateFrom($since);

        $decoded = $this->request(
            'GET',
            self::STATISTICS_BASE_URL.self::STATISTICS_ORDERS_ENDPOINT,
            $companyId,
            $connectionRef,
            self::STATISTICS_ORDERS_ENDPOINT,
            ['query' => ['dateFrom' => $dateFrom, 'flag' => 0]],
        );

        $rows = $this->listOfObjects($decoded['data'], $decoded['shape'], self::STATISTICS_ORDERS_ENDPOINT, null);

        // Постраничности у эндпоинта нет: он отдаёт весь поток изменений за
        // один ответ, поэтому hasMore всегда false.
        return new WbOrdersPage(
            rows: $rows,
            hasMore: false,
            nextToken: null,
            metadata: ['endpoint' => self::STATISTICS_ORDERS_ENDPOINT, 'dateFrom' => $dateFrom],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{data: array<array-key, mixed>, shape: mixed}
     */
    private function request(
        string $method,
        string $url,
        string $companyId,
        string $connectionRef,
        string $endpoint,
        array $options,
    ): array {
        $credentials = $this->credentials($companyId, $connectionRef);
        $startedAt = microtime(true);
        $statusCode = null;

        try {
            $response = $this->httpClient->request($method, $url, array_merge($options, [
                'headers' => [
                    'Authorization' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]));

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            // Исходное исключение НЕ прицепляется как previous.
            //
            // Его сообщение может нести DSN с учётными данными, а Messenger и
            // централизованный обработчик сериализуют всю цепочку — секрет,
            // который не записали напрямую, всё равно оказался бы в логах.
            // Наружу отдаём только класс: для классификации сбоя этого хватает.
            throw new ConnectorTransientException(sprintf('WB orders transport error for %s (%s).', $endpoint, $exception::class));
        } finally {
            $this->logger->info('WB orders API request finished.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'method' => $method,
                'endpoint' => $endpoint,
                'statusCode' => $statusCode,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        // 204 — легальный «изменений нет», а не ошибка.
        if (204 === $statusCode) {
            return ['data' => [], 'shape' => []];
        }

        $this->classifyStatus($statusCode, $headers, $endpoint);

        try {
            // Разбираем дважды: ассоциативно — ради данных, объектно — ради
            // ФОРМЫ. json_decode(..., true) превращает и `[]`, и `{}` в один и
            // тот же пустой массив, поэтому пустой объект вместо списка
            // проходил бы за корректный пустой ответ. Для statistics это не
            // безобидно: пустой ответ двигает курсор вперёд, и испорченный
            // ответ означал бы окончательный пропуск окна.
            //
            // Второй разбор стоит лишнего прохода по телу; страницы заказов
            // ограничены 1000 строк, и цена этого меньше цены пропуска.
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            $shape = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedConnectorResponseException(sprintf('WB %s returned invalid JSON.', $endpoint), 0, $exception);
        }

        if (!is_array($data)) {
            throw new MalformedConnectorResponseException(sprintf('WB %s returned an unexpected payload.', $endpoint));
        }

        return ['data' => $data, 'shape' => $shape];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function classifyStatus(?int $statusCode, array $headers, string $endpoint): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException(sprintf('WB orders auth failed for %s (HTTP %d).', $endpoint, (int) $statusCode));
        }

        if (429 === $statusCode) {
            throw new ConnectorRateLimitedException(sprintf('WB orders rate limited for %s.', $endpoint), $this->retryAfterSeconds($headers));
        }

        // 408 и 425 временные по смыслу и подлежат повтору наравне с 5xx.
        if (null === $statusCode || $statusCode >= 500 || 408 === $statusCode || 425 === $statusCode) {
            throw new ConnectorTransientException(sprintf('WB orders server error for %s (HTTP %d).', $endpoint, (int) $statusCode));
        }

        if (200 !== $statusCode) {
            throw new MalformedConnectorResponseException(sprintf('WB orders returned HTTP %d for %s.', $statusCode, $endpoint));
        }
    }

    /**
     * Список ОБЪЕКТОВ, а не просто массив.
     *
     * Форма берётся из объектного разбора: только он отличает пустой список от
     * пустого объекта. Данные — из ассоциативного, потому что дальше по
     * конвейеру ходят массивы.
     *
     * @return list<array<string, mixed>>
     */
    private function listOfObjects(mixed $value, mixed $shape, string $endpoint, ?string $field): array
    {
        $where = null === $field ? $endpoint : sprintf('%s.%s', $endpoint, $field);

        if (!is_array($shape) || !is_array($value) || !array_is_list($value)) {
            throw new MalformedConnectorResponseException(sprintf('WB %s is not a list.', $where));
        }

        $rows = [];
        foreach ($value as $index => $row) {
            // Пустой объект — тоже испорченная строка: опознать заказ в нём
            // нечем. Проверка формы и проверка содержательности здесь обе
            // нужны, и это та же строгость, что у Ozon-клиента.
            if (!is_array($row) || [] === $row || !($shape[$index] ?? null) instanceof \stdClass) {
                throw new MalformedConnectorResponseException(sprintf('WB %s contains a non-object row.', $where));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{api_key: string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        try {
            return $this->credentialProvider->read($companyId, $connectionRef);
        } catch (CredentialNotFoundException $exception) {
            throw new ConnectorAuthException('WB orders credentials were not found.', 0, $exception);
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfterSeconds(array $headers): int
    {
        $value = trim($headers['retry-after'][0] ?? '');

        return ctype_digit($value) ? max(1, (int) $value) : self::DEFAULT_RETRY_AFTER_SECONDS;
    }
}
