<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonCredentialProviderInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonOrdersClientTest extends TestCase
{
    private const COMPANY_ID = '0192f0c2-0000-7000-8000-000000000001';
    private const CONNECTION_ID = '0192f0c2-0000-7000-8000-000000000002';

    /**
     * Регрессия: конструктор ConnectorRateLimitedException требует
     * retryAfterSeconds, а вызывался с одним аргументом. На старом коде 429 от
     * Ozon давал ArgumentCountError вместо отложенного ре-диспатча — то есть
     * ровно там, где ретрай и нужен, загрузка падала насмерть.
     */
    public function testRateLimitCarriesRetryAfterFromHeader(): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => '45'],
        ]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(45, $exception->retryAfterSeconds());
        }
    }

    public function testRateLimitWithoutHeaderFallsBackToDefault(): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', ['http_code' => 429]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds());
        }
    }

    /**
     * Нечисловой Retry-After (HTTP-дата) не должен давать нулевую задержку:
     * ре-диспатч без паузы упёрся бы в тот же лимит немедленно.
     */
    #[DataProvider('unusableRetryAfterProvider')]
    public function testUnusableRetryAfterFallsBackToDefault(string $header): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => $header],
        ]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableRetryAfterProvider(): iterable
    {
        yield 'http date' => ['Wed, 01 Sep 2026 12:00:00 GMT'];
        yield 'negative' => ['-5'];
        yield 'empty' => [''];
    }

    public function testAuthStatusBecomesAuthException(): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => 403]));

        $this->expectException(ConnectorAuthException::class);
        $this->fetch($client);
    }

    public function testServerErrorBecomesTransientException(): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => 503]));

        $this->expectException(ConnectorTransientException::class);
        $this->fetch($client);
    }

    /**
     * Отсутствующий has_next раньше молча означал «страниц больше нет» и
     * обрывал обход на первой странице. Пропуск не виден ничем.
     */
    public function testFbsMissingHasNextIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":[]}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * Строка "false" приводилась к true и давала лишний круг пагинации.
     */
    public function testFbsStringHasNextIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":[],"has_next":"false"}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * Не-объект в списке раньше выбрасывался молча. У FBO это опаснее всего:
     * счётчик строк — единственный признак полной страницы, и выброшенный
     * элемент оборвал бы обход раньше времени.
     */
    public function testNonObjectPostingIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":[{"posting_number":"p-1"},"broken"]}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    public function testInvalidJsonIsMalformed(): void
    {
        $client = $this->client(new MockResponse('not json', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    /**
     * Ассоциативный контейнер проходит is_array(), но его count() участвует в
     * решении о пагинации: обход закончился бы на произвольном числе
     * элементов.
     */
    public function testAssociativeResultIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"first":{"posting_number":"p-1"}}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    public function testAssociativeFbsPostingsAreMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":{"a":{"posting_number":"p-1"}},"has_next":false}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * 408 и 425 — временные по смыслу и подлежат повтору ровно как 5xx. Без
     * отдельной ветки таймаут шлюза становился неповторяемым malformed
     * response и убивал прогон.
     */
    #[DataProvider('transientStatusProvider')]
    public function testTimeoutStatusesAreTransient(int $statusCode): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => $statusCode]));

        $this->expectException(ConnectorTransientException::class);
        $this->fetch($client);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function transientStatusProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'bad gateway' => [502];
        yield 'gateway timeout' => [504];
    }

    /**
     * Вложенный список после json_decode(..., true) — тоже массив, и прошёл бы
     * как posting: маппер отклонил бы его, но raw пометился бы DONE, а курсор
     * ушёл вперёд, превратив нарушение контракта в окончательный пропуск.
     */
    #[DataProvider('nonObjectPostingProvider')]
    public function testNonObjectPostingShapesAreMalformed(string $payload): void
    {
        $client = $this->client(new MockResponse($payload, ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectPostingProvider(): iterable
    {
        yield 'вложенный список' => ['{"result":[["broken"]]}'];
        yield 'пустой объект' => ['{"result":[{}]}'];
        yield 'скаляр' => ['{"result":["broken"]}'];
    }

    public function testNonObjectFbsPostingIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":[["broken"]],"has_next":false}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * Перепрос статуса ходит в другой эндпоинт, чем список. Схема выбирает
     * пару: FBS и FBO — разные версии API.
     */
    #[DataProvider('postingEndpointProvider')]
    public function testPostingRequestGoesToTheSchemeEndpointWithOnlyTheNumber(
        IngestOrderScheme $scheme,
        string $expectedUrl,
    ): void {
        $seen = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('{"result":{"posting_number":"P-1","status":"delivered"}}');
        });

        $client = new OzonOrdersClient($httpClient, $this->credentialProvider(), new NullLogger());
        $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, $scheme, 'P-1');

        self::assertIsArray($seen);
        self::assertSame('POST', $seen['method']);
        self::assertSame($expectedUrl, $seen['url']);

        // Дополнительные блоки не запрашиваются: ответ целиком ложится в raw
        // и хранится год, а перепросу нужен только статус.
        self::assertSame(['posting_number' => 'P-1'], json_decode((string) $seen['body'], true));
    }

    /**
     * @return iterable<string, array{scheme: IngestOrderScheme, expectedUrl: string}>
     */
    public static function postingEndpointProvider(): iterable
    {
        yield 'fbo' => [
            'scheme' => IngestOrderScheme::FBO,
            'expectedUrl' => 'https://api-seller.ozon.ru/v2/posting/fbo/get',
        ];
        yield 'fbs' => [
            'scheme' => IngestOrderScheme::FBS,
            'expectedUrl' => 'https://api-seller.ozon.ru/v3/posting/fbs/get',
        ];
    }

    /**
     * 404 — «Ozon такого отправления не знает». Заказ мог быть удалён или
     * номер устареть; ронять из-за него перепрос остальных нельзя.
     */
    public function testUnknownPostingReturnsNullInsteadOfThrowing(): void
    {
        $client = $this->client(new MockResponse('{"code":404}', ['http_code' => 404]));

        self::assertNull($client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1'));
    }

    /**
     * Ответ с чужим номером записал бы статус одного отправления другому, и
     * подмена никак себя не проявила бы: статус выглядит правдоподобно всегда.
     */
    public function testPostingNumberMismatchIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"posting_number":"OTHER","status":"delivered"}}'));

        $this->expectException(MalformedConnectorResponseException::class);
        $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('postingFailureProvider')]
    public function testPostingHttpFailuresAreClassifiedLikeTheList(int $statusCode, string $expected): void
    {
        $client = $this->client(new MockResponse('{"error":"x"}', ['http_code' => $statusCode]));

        $this->expectException($expected);
        $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
    }

    /**
     * @return iterable<string, array{statusCode: int, expected: class-string<\Throwable>}>
     */
    public static function postingFailureProvider(): iterable
    {
        yield 'unauthorized' => ['statusCode' => 401, 'expected' => ConnectorAuthException::class];
        yield 'rate limited' => ['statusCode' => 429, 'expected' => ConnectorRateLimitedException::class];
        yield 'gateway timeout' => ['statusCode' => 504, 'expected' => ConnectorTransientException::class];
        yield 'teapot' => ['statusCode' => 418, 'expected' => MalformedConnectorResponseException::class];
    }

    /**
     * Нарушивший контракт ответ едет вместе с исключением: именно он и нужен
     * вызывающему как доказательство для аудита.
     */
    public function testMalformedResponseCarriesTheDecodedPayload(): void
    {
        $client = $this->client(new MockResponse('{"result":[],"message":"nothing"}'));

        try {
            $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
            self::fail('Response without a result object must be malformed.');
        } catch (MalformedConnectorResponseException $exception) {
            self::assertSame(['result' => [], 'message' => 'nothing'], $exception->decodedPayload());
        }
    }

    /**
     * Невалидный JSON — единственный случай, когда доказательства нет: разбирать
     * нечего, и класть в сырьё тоже нечего.
     */
    public function testInvalidJsonHasNoDecodedPayload(): void
    {
        $client = $this->client(new MockResponse('not json at all'));

        try {
            $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
            self::fail('Invalid JSON must be malformed.');
        } catch (MalformedConnectorResponseException $exception) {
            self::assertNull($exception->decodedPayload());
        }
    }

    /**
     * Неожиданный HTTP-код с валидным телом: тело — доказательство, ради
     * которого аудит и существует, и терять его нельзя. Разбор статуса шёл до
     * json_decode(), поэтому исключение уходило без payload.
     */
    public function testUnexpectedHttpCodeCarriesTheResponseBody(): void
    {
        $client = $this->client(new MockResponse('{"code":13,"message":"unexpected"}', ['http_code' => 418]));

        try {
            $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
            self::fail('Unexpected HTTP code must be malformed.');
        } catch (MalformedConnectorResponseException $exception) {
            self::assertSame(['code' => 13, 'message' => 'unexpected'], $exception->decodedPayload());
        }
    }

    /**
     * Схема выбирает эндпоинт исчерпывающе. Тернарное «FBS или иначе FBO»
     * отправляло бы заказ с неизвестной схемой в FBO, и тот получал бы ложный
     * 404 вместо честной ошибки вызывающего.
     */
    public function testUnknownSchemeIsRejectedInsteadOfDefaultingToFbo(): void
    {
        $client = $this->client(new MockResponse('{"result":{"posting_number":"P-1","status":"delivered"}}'));

        $this->expectException(\InvalidArgumentException::class);
        $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::UNKNOWN, 'P-1');
    }

    /**
     * Отсутствующие учётные данные — отказ авторизации, а не внутренняя
     * ошибка: цикл перепроса обязан пропустить подключение и продолжить
     * остальные. WB-клиент так делает давно, Ozon пропускал исключение мимо.
     */
    public function testMissingCredentialsBecomeAuthFailure(): void
    {
        $provider = new class implements OzonCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                throw new CredentialNotFoundException('no credentials');
            }
        };

        $client = new OzonOrdersClient(new MockHttpClient(new MockResponse('{}')), $provider, new NullLogger());

        $this->expectException(ConnectorAuthException::class);
        $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'P-1');
    }

    private function fetch(OzonOrdersClient $client, IngestOrderScheme $scheme = IngestOrderScheme::FBO): void
    {
        $client->fetchPostings(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            $scheme,
            new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T01:00:00+00:00'),
            100,
            0,
        );
    }

    /**
     * Номер отправления обязан быть каноническим — клиент его не чинит.
     *
     * Срезав пробелы, клиент спросил бы «P-1» вместо « P-1 » и сверил бы
     * номер ответа уже с обрезанным: статус чужого отправления вернулся бы
     * как ответ на наш заказ. Отказ — ДО любого HTTP-вызова.
     */
    #[DataProvider('nonCanonicalPostingNumberProvider')]
    public function testNonCanonicalPostingNumberIsRejectedBeforeAnyRequest(string $postingNumber): void
    {
        $requests = 0;
        $client = new OzonOrdersClient(
            new MockHttpClient(function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('{}', ['http_code' => 200]);
            }),
            $this->credentialProvider(),
            new NullLogger(),
        );

        try {
            $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, $postingNumber);
            self::fail('Неканонический номер обязан быть отвергнут.');
        } catch (\InvalidArgumentException) {
            // Ожидаемо.
        }

        self::assertSame(0, $requests, 'До HTTP дело доходить не должно.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalPostingNumberProvider(): iterable
    {
        yield 'пусто' => [''];
        yield 'только пробелы' => ['   '];
        yield 'пробелы по краям' => [' P-1 '];
    }

    /**
     * Не-объект в теле — тоже доказательство.
     *
     * `[]`, `"broken"` или `0` нарушают контракт ровно так же, как объект без
     * нужного поля. Пока доказательством считался только объект, такой ответ
     * давал пустой `decodedPayload`, сырья не появлялось вовсе — и разбирать
     * дефект интеграции было не по чему.
     */
    #[DataProvider('nonObjectPayloadProvider')]
    public function testNonObjectPayloadOfAnUnexpectedStatusIsStillEvidence(string $payload, mixed $expected): void
    {
        $client = $this->client(new MockResponse($payload, ['http_code' => 418]));

        try {
            $client->fetchPosting(self::COMPANY_ID, self::CONNECTION_ID, IngestOrderScheme::FBO, 'posting-1');
            self::fail('Неожиданный код обязан быть нарушением контракта.');
        } catch (MalformedConnectorResponseException $exception) {
            self::assertSame(['_malformed_response' => $expected], $exception->decodedPayload());
            self::assertTrue($exception->isEndpointWide());
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function nonObjectPayloadProvider(): iterable
    {
        yield 'пустой список' => ['[]', []];
        yield 'список строк' => ['["a","b"]', ['a', 'b']];
        yield 'строка' => ['"broken"', 'broken'];
        yield 'число' => ['0', 0];
    }

    private function client(MockResponse $response): OzonOrdersClient
    {
        return new OzonOrdersClient(
            new MockHttpClient($response),
            $this->credentialProvider(),
            new NullLogger(),
        );
    }

    private function credentialProvider(): OzonCredentialProviderInterface
    {
        return new class implements OzonCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                return ['client_id' => 'client-id', 'api_key' => 'api-key'];
            }
        };
    }
}
