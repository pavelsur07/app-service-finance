<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Отправления Ozon Seller API.
 *
 * Две схемы — два эндпоинта с разной формой ответа:
 * - FBO `/v2/posting/fbo/list` отдаёт `result` списком и НЕ отдаёт `has_next`;
 * - FBS `/v3/posting/fbs/list` отдаёт `result.postings` и `result.has_next`.
 *
 * Разница формы — не деталь реализации, а причина, по которой признак «есть ещё
 * страницы» вычисляется по-разному: у FBO завершение определяется по неполной
 * странице, у FBS — по флагу.
 */
final readonly class OzonOrdersClient implements OzonOrdersClientInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const FBO_ENDPOINT = '/v2/posting/fbo/list';
    private const FBS_ENDPOINT = '/v3/posting/fbs/list';
    private const FBO_GET_ENDPOINT = '/v2/posting/fbo/get';
    private const FBS_GET_ENDPOINT = '/v3/posting/fbs/get';
    private const TIMEOUT_SECONDS = 60;
    private const DEFAULT_RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OzonCredentialProviderInterface $credentialProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $limit,
        int $offset,
    ): OzonRawPage {
        $credentials = $this->credentials($companyId, $connectionRef);
        $endpoint = IngestOrderScheme::FBS === $scheme ? self::FBS_ENDPOINT : self::FBO_ENDPOINT;

        $body = [
            'dir' => 'ASC',
            'filter' => [
                'since' => $since->format(\DATE_ATOM),
                'to' => $to->format(\DATE_ATOM),
            ],
            'limit' => $limit,
            'offset' => $offset,
            'with' => ['analytics_data' => true, 'financial_data' => true],
        ];

        if (IngestOrderScheme::FBO === $scheme) {
            $body['translit'] = true;
        }

        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.$endpoint, [
                'headers' => [
                    'Client-Id' => (string) $credentials['client_id'],
                    'Api-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $payload = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            // Исходное исключение не прицепляется: его сообщение может нести
            // DSN с учётными данными, а обработчики сериализуют всю цепочку.
            throw new ConnectorTransientException(sprintf('Ozon orders transport error for %s (%s).', $endpoint, $exception::class));
        }

        // Метод, эндпоинт, статус и длительность — обязательный минимум для
        // внешнего HTTP. Тело ответа не логируется никогда: в заказах есть
        // адреса доставки и данные покупателей.
        $this->logger->info('Ozon orders page fetched.', [
            'companyId' => $companyId,
            'connectionRef' => $connectionRef,
            'method' => 'POST',
            'endpoint' => $endpoint,
            'statusCode' => $statusCode,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'offset' => $offset,
        ]);

        $this->classifyStatus($statusCode, $headers, $endpoint);

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedConnectorResponseException(sprintf('Ozon orders returned invalid JSON for %s.', $endpoint), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new MalformedConnectorResponseException(sprintf('Ozon orders returned unexpected payload for %s.', $endpoint));
        }

        return $this->toPage($decoded, $scheme, $limit, $endpoint);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function toPage(array $decoded, IngestOrderScheme $scheme, int $limit, string $endpoint): OzonRawPage
    {
        $result = $decoded['result'] ?? null;

        if (IngestOrderScheme::FBS === $scheme) {
            if (!is_array($result) || !is_array($result['postings'] ?? null) || !array_is_list($result['postings'])) {
                throw new MalformedConnectorResponseException(sprintf('Ozon orders response has no result.postings for %s.', $endpoint));
            }

            $rows = $this->postingRows($result['postings'], $endpoint);

            // has_next обязан быть булевым.
            //
            // Отсутствующий ключ раньше молча означал `false` и обрывал
            // пагинацию на первой странице, а строка "false" приводилась к
            // true и давала лишний круг. Оба случая — испорченный ответ, и
            // молчать о нём нельзя: пропуск страниц не виден никак.
            $hasNext = $result['has_next'] ?? null;
            if (!is_bool($hasNext)) {
                throw new MalformedConnectorResponseException(sprintf('Ozon orders response has non-boolean result.has_next for %s.', $endpoint));
            }

            return new OzonRawPage($rows, $hasNext, null, []);
        }

        // Именно СПИСОК, а не объект: ассоциативный контейнер прошёл бы
        // is_array(), а его count() участвует в решении о пагинации — обход
        // закончился бы на произвольном числе элементов.
        if (!is_array($result) || !array_is_list($result)) {
            throw new MalformedConnectorResponseException(sprintf('Ozon orders response has no result list for %s.', $endpoint));
        }

        $rows = $this->postingRows($result, $endpoint);

        // FBO не отдаёт has_next: полная страница означает «возможно, есть
        // ещё». Ценой одного лишнего запроса на ровно кратном объёме это
        // честнее, чем гадать.
        //
        // Именно поэтому отбрасывать элементы здесь нельзя: выброшенный
        // элемент уменьшил бы счётчик, страница перестала бы считаться полной
        // и обход закончился бы раньше времени — молча и с потерей заказов.
        return new OzonRawPage($rows, count($rows) >= $limit, null, []);
    }

    public function fetchPosting(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        string $postingNumber,
    ): ?array {
        $postingNumber = trim($postingNumber);
        if ('' === $postingNumber) {
            throw new \InvalidArgumentException('Ozon posting number cannot be empty.');
        }

        $credentials = $this->credentials($companyId, $connectionRef);
        $endpoint = IngestOrderScheme::FBS === $scheme ? self::FBS_GET_ENDPOINT : self::FBO_GET_ENDPOINT;

        // Никаких дополнительных блоков (`analytics_data`, `financial_data`):
        // перепросу нужен только статус, а ответ целиком ложится в raw. Просить
        // адреса и финансы, которые никто не читает, значит хранить лишние
        // персональные данные ровно год.
        $body = ['posting_number' => $postingNumber];

        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.$endpoint, [
                'headers' => [
                    'Client-Id' => (string) $credentials['client_id'],
                    'Api-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $payload = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new ConnectorTransientException(sprintf('Ozon posting transport error for %s (%s).', $endpoint, $exception::class));
        }

        $this->logger->info('Ozon posting fetched.', [
            'companyId' => $companyId,
            'connectionRef' => $connectionRef,
            'method' => 'POST',
            'endpoint' => $endpoint,
            'statusCode' => $statusCode,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        // Ozon отвечает 404 на неизвестный номер. Это не сбой цикла: заказ мог
        // быть удалён или номер устареть, и ронять из-за него перепрос всех
        // остальных заказов нельзя.
        if (404 === $statusCode) {
            return null;
        }

        $this->classifyStatus($statusCode, $headers, $endpoint);

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedConnectorResponseException(sprintf('Ozon posting returned invalid JSON for %s.', $endpoint), 0, $exception);
        }

        $result = is_array($decoded) ? ($decoded['result'] ?? null) : null;

        // Отправление обязано быть непустым объектом: список или пустышка —
        // испорченный ответ, а не отсутствующий заказ.
        if (!is_array($result) || [] === $result || array_is_list($result)) {
            throw new MalformedConnectorResponseException(sprintf('Ozon posting response has no result object for %s.', $endpoint));
        }

        // Номер в ответе обязан совпасть с запрошенным. Иначе статус чужого
        // отправления был бы записан этому заказу, и расхождение никак себя
        // не проявило бы: статус выглядит правдоподобно всегда.
        $returnedNumber = $result['posting_number'] ?? null;
        if (!is_string($returnedNumber) || $returnedNumber !== $postingNumber) {
            throw new MalformedConnectorResponseException(sprintf('Ozon posting response number does not match the request for %s.', $endpoint));
        }

        return $result;
    }

    /**
     * Отсутствующие учётные данные — это отказ авторизации, а не внутренняя
     * ошибка: цикл перепроса обязан пропустить такое подключение и продолжить
     * остальные, а не упасть целиком.
     *
     * @return array{api_key: string, client_id: ?string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        try {
            return $this->credentialProvider->read($companyId, $connectionRef);
        } catch (CredentialNotFoundException $exception) {
            throw new ConnectorAuthException('Ozon orders credentials were not found.', 0, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function postingRows(array $rows, string $endpoint): array
    {
        $result = [];
        foreach ($rows as $row) {
            // Отправление обязано быть непустым JSON-ОБЪЕКТОМ. После
            // json_decode(..., true) вложенный список вроде ["broken"] тоже
            // является массивом и прошёл бы как posting: маппер отклонил бы
            // его в MAPPER_FAILURE, но raw пометился бы DONE, а курсор ушёл
            // вперёд — нарушение контракта API стало бы окончательным
            // пропуском. Пустой объект тоже невалиден: опознать его нечем.
            if (!is_array($row) || [] === $row || array_is_list($row)) {
                throw new MalformedConnectorResponseException(sprintf('Ozon orders response contains a non-object posting for %s.', $endpoint));
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Разбор HTTP-статуса, общий для списка и для одиночного отправления.
     *
     * Одно место намеренно: два перечня кодов рано или поздно разошлись бы, и
     * тот же таймаут в одном пути повторялся бы, а в другом убивал прогон.
     *
     * @param array<string, list<string>> $headers
     */
    private function classifyStatus(int $statusCode, array $headers, string $endpoint): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException(sprintf('Ozon orders auth failed for %s (HTTP %d).', $endpoint, $statusCode));
        }

        if (429 === $statusCode) {
            throw new ConnectorRateLimitedException(sprintf('Ozon orders rate limited for %s.', $endpoint), $this->retryAfterSeconds($headers));
        }

        // 408 и 425 — временные по смыслу: истёкший таймаут запроса и
        // «слишком рано» подлежат повтору ровно так же, как 5xx. Без этой
        // ветки они становились неповторяемым malformed response, то есть
        // таймаут шлюза убивал прогон.
        if ($statusCode >= 500 || 408 === $statusCode || 425 === $statusCode) {
            throw new ConnectorTransientException(sprintf('Ozon orders server error for %s (HTTP %d).', $endpoint, $statusCode));
        }

        if (200 !== $statusCode) {
            throw new MalformedConnectorResponseException(sprintf('Ozon orders returned HTTP %d for %s.', $statusCode, $endpoint));
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfterSeconds(array $headers): int
    {
        // Ozon шлёт Retry-After в секундах либо не шлёт вовсе; HTTP-дату не разбираем.
        $value = trim($headers['retry-after'][0] ?? '');

        return ctype_digit($value) ? max(1, (int) $value) : self::DEFAULT_RETRY_AFTER_SECONDS;
    }
}
