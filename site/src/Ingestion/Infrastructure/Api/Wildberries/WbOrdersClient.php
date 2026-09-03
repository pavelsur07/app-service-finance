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
    /** Предел на одну ось статуса; склейка обеих обязана влезть в VARCHAR(255). */
    private const STATUS_AXIS_MAX_LENGTH = 100;

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

    public function fetchMarketplaceStatuses(string $companyId, string $connectionRef, array $orderIds): WbOrderStatusPage
    {
        if ([] === $orderIds) {
            return new WbOrderStatusPage();
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

        $evidence = self::evidence($decoded['data']);
        $rows = $this->listOfObjects($decoded['data']['orders'] ?? null, $decoded['shape']->orders ?? null, self::ORDERS_STATUS_ENDPOINT, 'orders', $evidence);

        // Спрошенные номера — множество, по которому проверяется КАЖДЫЙ
        // вернувшийся. Чужой номер означает, что ответ относится не к нашему
        // запросу; принять его молча значило бы записать статус постороннего
        // заказа или потерять доказательство, объявив наш заказ ненайденным.
        $requested = array_flip($orderIds);

        $indexed = [];
        $rejected = 0;
        $rejectedIds = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $ours = is_int($id) && isset($requested[$id]);

            // ПОВТОР номера — брак ВСЕГО номера, а не одной лишней строки.
            //
            // Отбраковать вторую строку и оставить первую значило бы выбрать
            // статус по порядку в ответе: две строки одного заказа
            // противоречат друг другу, и какая из них верна — неизвестно. Если
            // произвольно выбранная окажется терминальной, заказ навсегда
            // выпадет из перепроса. Поэтому уже принятая строка снимается
            // тоже, а номер уходит в отбракованные.
            if ($ours && isset($seen[$id])) {
                unset($indexed[$id]);
                ++$rejected;
                $rejectedIds[] = $id;

                continue;
            }

            if ($ours) {
                $seen[$id] = true;
            }

            // Повреждённая СТРОКА отбраковывается, а не роняет ответ.
            //
            // Ответ — это, как правило, всё подключение целиком, поэтому
            // исключение на первой кривой строке навсегда блокировало бы
            // обновление всех остальных корректных заказов. Нарушение формы
            // всего ответа по-прежнему исключение: см. listOfObjects().
            $accepted = self::statusRow($row, $requested);

            if (null === $accepted) {
                ++$rejected;

                // Номер отбракованной строки, если он вообще пригоден:
                // вызывающий обязан отличить «строка была, но кривая» от
                // «заказа в ответе не оказалось». Иначе один и тот же заказ
                // считался бы и как invalid, и как missing, а в аудит уходило
                // бы ложное «маркетплейс заказ не вернул».
                if ($ours) {
                    $rejectedIds[] = $id;
                }

                continue;
            }

            /** @var int $acceptedId */
            $acceptedId = $accepted['id'];
            $indexed[$acceptedId] = $accepted;
        }

        // Номер, снятый как повторный, мог быть принят ДО повтора: тогда он
        // попал и в `$indexed`, и в `$rejectedIds`. Первое уже снято выше;
        // здесь остаётся не отдать наружу статус, которому мы не верим.
        foreach ($rejectedIds as $rejectedId) {
            unset($indexed[$rejectedId]);
        }

        if ($rejected > 0) {
            $this->logger->warning('WB status rows were rejected as malformed.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'rejectedRows' => $rejected,
                'acceptedRows' => count($indexed),
            ]);
        }

        return new WbOrderStatusPage(
            statuses: $indexed,
            rejectedRows: $rejected,
            rejectedIds: array_values(array_unique($rejectedIds)),
            evidence: $rejected > 0 ? $evidence : null,
            // Строки БЕЗ нормализации: сырьё обязано содержать ответ
            // маркетплейса, а не наш разбор этого ответа.
            auditRows: $rows,
        );
    }

    /**
     * Пригодна ли строка статуса. `null` — отбраковать.
     *
     * Только ФОРМА строки. Повторы номеров разбираются вызывающим: там видно
     * уже принятое, и снять его — часть того же решения.
     *
     * @param array<string, mixed> $row
     * @param array<int, int> $requested спрошенные номера
     *
     * @return array<string, mixed>|null
     */
    private static function statusRow(array $row, array $requested): ?array
    {
        $id = $row['id'] ?? null;

        // Чужой номер означает, что строка относится не к нашему запросу;
        // принять её значило бы записать статус постороннего заказа.
        if (!is_int($id) || !isset($requested[$id])) {
            return null;
        }

        // Обе оси обязательны и обязаны быть ПРИГОДНЫМИ токенами.
        //
        // `is_string()` мало: пустая строка становилась бы НАБЛЮДЕНИЕМ статуса
        // с пустыми осями — заказ получал бы UNKNOWN, ложное событие журнала и
        // статусную отметку, закрывающую дорогу настоящему статусу. Длина тоже
        // не мелочь: склейка осей уходит в `raw_status`, а это VARCHAR(255).
        $supplierStatus = self::statusAxis($row['supplierStatus'] ?? null);
        $wbStatus = self::statusAxis($row['wbStatus'] ?? null);

        if (null === $supplierStatus || null === $wbStatus) {
            return null;
        }

        // Поле обязательно, а не «если прислали»: строка без него — такое же
        // неполное статусное наблюдение, как строка без осей.
        if (!is_bool($row['isCancellable'] ?? null)) {
            return null;
        }

        // Возвращаются НОРМАЛИЗОВАННЫЕ оси, а не исходные. Проверять
        // очищенное, а хранить исходное — значит пропустить `" complete "`,
        // которое дальше не найдётся в словаре и станет UNKNOWN, и строку из
        // токена с сотнями пробелов, которая переполнит колонку и откатит
        // транзакцию всего подключения.
        $row['supplierStatus'] = $supplierStatus;
        $row['wbStatus'] = $wbStatus;

        return $row;
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

        $this->classifyStatus($statusCode, $headers, $endpoint, $body);

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
    private function classifyStatus(?int $statusCode, array $headers, string $endpoint, ?string $payload = null): void
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

        // Неожиданный код — свойство ЭНДПОИНТА, а не пачки: следующий запрос
        // вернёт то же самое. Без этого признака цикл перепроса принял бы
        // сломанный API за набор испорченных пачек, продолжил долбить его и
        // завершил прогон успехом.
        if (200 !== $statusCode) {
            $evidence = null === $payload ? null : self::evidence(json_decode($payload, true));

            throw new MalformedConnectorResponseException(sprintf('WB orders returned HTTP %d for %s.', $statusCode, $endpoint), decodedPayload: $evidence, endpointWide: true);
        }
    }

    /**
     * Ответ как доказательство для аудита: объект годится, всё остальное
     * (список, скаляр, неразобранное) хранить нечем — доказательства там и
     * нет. В лог это не идёт: там разрешены идентификаторы и статусы, но не
     * тела ответов внешних API.
     *
     * @return array<string, mixed>|null
     */
    private static function evidence(mixed $decoded): ?array
    {
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Ось статуса: непустая строка, влезающая в колонку вместе со второй осью.
     *
     * Предел на ось, а не на склейку: `supplierStatus=…;wbStatus=…` добавляет
     * 25 служебных символов, и две оси по 100 дают 225 при колонке в 255.
     * Проверять уже собранную строку поздно — к тому моменту непонятно, какая
     * из осей виновата.
     */
    private static function statusAxis(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $axis = trim($value);

        if ('' === $axis || mb_strlen($axis) > self::STATUS_AXIS_MAX_LENGTH) {
            return null;
        }

        return $axis;
    }

    /**
     * Список ОБЪЕКТОВ, а не просто массив.
     *
     * Форма берётся из объектного разбора: только он отличает пустой список от
     * пустого объекта. Данные — из ассоциативного, потому что дальше по
     * конвейеру ходят массивы.
     *
     * @param array<string, mixed>|null $evidence разобранный ответ целиком — доказательство для аудита
     *
     * @return list<array<string, mixed>>
     */
    private function listOfObjects(mixed $value, mixed $shape, string $endpoint, ?string $field, ?array $evidence = null): array
    {
        $where = null === $field ? $endpoint : sprintf('%s.%s', $endpoint, $field);

        if (!is_array($shape) || !is_array($value) || !array_is_list($value)) {
            throw new MalformedConnectorResponseException(sprintf('WB %s is not a list.', $where), decodedPayload: $evidence);
        }

        $rows = [];
        foreach ($value as $index => $row) {
            // Пустой объект — тоже испорченная строка: опознать заказ в нём
            // нечем. Проверка формы и проверка содержательности здесь обе
            // нужны, и это та же строгость, что у Ozon-клиента.
            if (!is_array($row) || [] === $row || !($shape[$index] ?? null) instanceof \stdClass) {
                throw new MalformedConnectorResponseException(sprintf('WB %s contains a non-object row.', $where), decodedPayload: $evidence);
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
