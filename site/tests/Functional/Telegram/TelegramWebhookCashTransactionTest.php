<?php

declare(strict_types=1);

namespace App\Tests\Functional\Telegram;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ImportJob;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stage 2 (TG-WEBHOOK-DDS-FIX): ошибки создания ДДС больше не глушатся молча —
 * пользователь получает понятное сообщение, webhook всегда отвечает 200.
 */
final class TelegramWebhookCashTransactionTest extends WebTestCaseBase
{
    private const TG_USER_ID = '67890';
    private const CHAT_ID = 12345;

    public function testClosedPeriodReportsReasonToUser(): void
    {
        $client = static::createClient();
        $this->resetDb();

        // Закрытый период: дата операции (сегодня) раньше замка → доменное исключение «Период закрыт»
        $this->seedBoundUserWithAccount(financeLockBefore: new \DateTimeImmutable('2099-01-01'));

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        $this->postUpdate($client, '+1000 доход');

        self::assertResponseIsSuccessful();
        $body = $this->lastSentMessageText($captured);
        self::assertStringContainsString('Период закрыт', $body);

        // Транзакция не создана
        self::assertSame(0, $this->countCashTransactions());
    }

    public function testZeroAmountReturnsFormatHint(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        $this->postUpdate($client, '0 расход');

        self::assertResponseIsSuccessful();
        $body = $this->lastSentMessageText($captured);
        self::assertStringContainsString('Формат', $body);

        self::assertSame(0, $this->countCashTransactions());
    }

    public function testValidOperationIsRecorded(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        $body = $this->lastSentMessageText($captured);
        self::assertStringContainsString('Записал', $body);

        self::assertSame(1, $this->countCashTransactions());
    }

    public function testPlusSignMeansInflowEvenWithPaymentWord(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        // "+" имеет приоритет над словом "оплата" (которое само по себе → расход)
        $this->postUpdate($client, '+1000 оплата прочие');

        self::assertResponseIsSuccessful();
        $body = $this->lastSentMessageText($captured);
        self::assertStringContainsString('доход', $body);
        self::assertStringNotContainsString('расход', $body);
    }

    public function testMinusSignMeansOutflow(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        $this->postUpdate($client, '-500 поступление прочие');

        self::assertResponseIsSuccessful();
        $body = $this->lastSentMessageText($captured);
        self::assertStringContainsString('расход', $body);
    }

    /**
     * Регрессия: дедуп по external_id молчал — ни операции, ни ответа. Снаружи это
     * неотличимо от «бот умер», а именно так дефект и был заявлен Владельцем.
     */
    public function testDuplicateMessageAnswersUserInsteadOfSilence(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->seedBoundUserWithAccount(financeLockBefore: null);

        // Два запроса в одном тесте: без этого ядро пересоздаётся между ними и мок
        // http_client теряется — второй апдейт ушёл бы в реальный Telegram
        $client->disableReboot();

        $captured = [];
        $this->captureTelegramCalls($client, $captured);

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        // Один и тот же message_id: external_id = sha256(botId|chatId|messageId) совпадёт
        $this->postUpdate($client, '+1500 доход');
        self::assertStringContainsString('Записал', $this->lastSentMessageText($captured));

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('уже записана', $this->lastSentMessageText($captured));

        // Дубль по-прежнему не создаётся — чинится молчание, а не идемпотентность
        self::assertSame(1, $this->countCashTransactions());

        // Ретрай апдейта самим Telegram — рутина: будить человека на ней нельзя
        self::assertTrue($logHandler->hasWarningThatContains('дубль не создан'));
        self::assertFalse($logHandler->hasErrorThatContains('дубль не создан'));
    }

    /**
     * Регрессия: сбой канала уходил в error_log мимо Monolog, поэтому в GlitchTip
     * было пусто не потому, что всё исправно, а потому, что туда ничего не попадало.
     * 403 без JSON — это ответ шлюза tg-gateway (ipAllowList), а не Telegram.
     */
    public function testBrokenChannelIsLoggedAsError(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse('Forbidden', ['http_code' => 403]),
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();

        // Канал проверяется явно: если автовайринг по имени аргумента $telegramLogger
        // тихо откатится на дефолтный канал app, запись всё равно будет — но на проде
        // уедет в main/fingers_crossed, и вся правка беззвучно отменится при зелёном тесте
        self::assertTrue(
            $logHandler->hasRecordThatPasses(
                static fn ($record): bool => 'telegram' === $record->channel
                    && str_contains($record->message, 'Канал Telegram сломан'),
                Level::Error,
            ),
            'Недоставленный ответ обязан быть ERROR именно в канале telegram: иначе отказ канала невидим в GlitchTip.',
        );
    }

    /**
     * Прокси со сбитым location отдаёт 200 и HTML. Признака отказа в теле нет, но и
     * ответа пользователь не получил — по статусу это неотличимо от успеха.
     */
    public function testHttp200WithNonJsonBodyIsTreatedAsBrokenChannel(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse('<html><body>502 Bad Gateway</body></html>'),
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue(
            $logHandler->hasErrorThatContains('Канал Telegram сломан'),
            'Ответ без ok:true — не успех: пользователь ничего не получил.',
        );
    }

    /**
     * Токен бота лежит в URL Bot API, а HttpClient подставляет URL в текст исключения.
     * Отдать Throwable логгеру целиком — значит положить токен в GlitchTip.
     */
    public function testBotTokenNeverReachesTheLog(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static function (): MockResponse {
                throw new TransportException('Idle timeout reached for "https://tg.example.test/bot-api/botTEST-TOKEN/sendMessage".');
            },
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue($logHandler->hasErrorThatContains('Канал Telegram сломан'));
        $this->assertNoBotTokenInLog($logHandler);
    }

    private function assertNoBotTokenInLog(TestHandler $logHandler): void
    {
        foreach ($logHandler->getRecords() as $record) {
            $serialized = json_encode($record->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(
                'TEST-TOKEN',
                $serialized,
                'Токен бота не должен попадать в лог ни в одном поле записи.',
            );
        }
    }

    /**
     * Обратная сторона того же правила: отказ самого Telegram по одному сообщению
     * (валидный JSON с ok:false) — не инцидент канала и будить человека не должен.
     */
    public function testTelegramRejectionIsWarningNotError(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                json_encode(
                    // error_code Bot API присылает всегда; без него ответ неотличим от
                    // подделки прокси и классифицируется консервативно — как поломка канала
                    ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'],
                    \JSON_THROW_ON_ERROR,
                ),
                ['http_code' => 403],
            ),
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue(
            $logHandler->hasWarningThatContains('Telegram отклонил сообщение'),
            'Отказ Telegram по конкретному сообщению должен оставаться WARNING.',
        );
        self::assertFalse(
            $logHandler->hasErrorThatContains('Канал Telegram сломан'),
            'Блокировка бота одним пользователем — не поломка канала и не повод для алерта.',
        );
    }

    /**
     * Системная ошибка Bot API приходит таким же JSON с ok:false, как и адресный отказ,
     * поэтому различать их можно только по коду. Ретраев здесь нет: 429 и 401 сами не
     * рассосутся и означают, что ответа не получит уже никто.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('systemErrorProvider')]
    public function testSystemApiErrorIsErrorNotWarning(int $httpCode, array $body): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                json_encode($body, \JSON_THROW_ON_ERROR),
                ['http_code' => $httpCode],
            ),
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue(
            $logHandler->hasErrorThatContains('Канал Telegram сломан'),
            'Системная ошибка Bot API обязана быть ERROR: канал сломан для всех пользователей.',
        );
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function systemErrorProvider(): iterable
    {
        yield 'invalid token' => [401, [
            'ok' => false,
            'error_code' => 401,
            'description' => 'Unauthorized',
        ]];

        yield 'telegram outage' => [500, [
            'ok' => false,
            'error_code' => 500,
            'description' => 'Internal Server Error',
        ]];

        // 400 — не адресный отказ: Bot API отдаёт его и на нашу сломанную сборку
        // сообщения, и тогда 400 получат все пользователи сразу
        yield 'our own malformed request' => [400, [
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: message text is empty',
        ]];
    }

    /**
     * Таймаут таблица CLAUDE.md тоже относит к самопроходящим: одна упавшая попытка
     * ещё не означает, что канал недоступен всем. Токен при этом не должен утечь —
     * именно в тексте таймаут-исключения HttpClient и печатает URL Bot API.
     */
    public function testTimeoutIsWarningAndKeepsTokenOut(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static function (): MockResponse {
                throw new TimeoutException('Idle timeout reached for "https://tg.example.test/bot-api/botTEST-TOKEN/sendMessage".');
            },
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue($logHandler->hasWarningThatContains('не уложился в таймаут'));
        self::assertFalse($logHandler->hasErrorThatContains('Канал Telegram сломан'));
        $this->assertNoBotTokenInLog($logHandler);
    }

    /**
     * Обратная сторона: flood control назван в таблице логирования CLAUDE.md как
     * WARNING — он снимается сам со следующим сообщением.
     */
    public function testRateLimitStaysWarning(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                json_encode(
                    ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 30'],
                    \JSON_THROW_ON_ERROR,
                ),
                ['http_code' => 429],
            ),
        ));

        /** @var TestHandler $logHandler */
        $logHandler = static::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $this->postUpdate($client, '+1500 доход');

        self::assertResponseIsSuccessful();
        self::assertTrue($logHandler->hasWarningThatContains('Telegram отклонил сообщение'));
        self::assertFalse($logHandler->hasErrorThatContains('Канал Telegram сломан'));
    }

    public function testDocumentUploadIsStoredInObjectStorage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedBoundUserWithAccount(financeLockBefore: null);

        $csv = "Дата;Сумма\n01.12.2025;1000\n";
        $client->getContainer()->set('http_client', new MockHttpClient(
            static function (string $method, string $url) use ($csv): MockResponse {
                if (str_contains($url, '/getFile')) {
                    return new MockResponse(json_encode(
                        ['ok' => true, 'result' => ['file_path' => 'documents/file_5.csv']],
                        \JSON_THROW_ON_ERROR,
                    ));
                }
                if (str_contains($url, '/file/bot')) {
                    return new MockResponse($csv);
                }

                return new MockResponse(json_encode(['ok' => true, 'result' => true], \JSON_THROW_ON_ERROR));
            },
        ));

        $this->postDocument($client, 'statement.csv');

        self::assertResponseIsSuccessful();

        /** @var ObjectStorageInterface $storage */
        $storage = $client->getContainer()->get(ObjectStorageInterface::class);
        $key = sprintf('telegram-imports/%s.csv', hash('sha256', $csv));

        self::assertTrue($storage->exists($key), 'Файл документа должен быть записан в объектное хранилище.');
        self::assertSame($csv, $storage->read($key));
        self::assertCount(1, $this->em()->getRepository(ImportJob::class)->findAll());

        $storage->delete($key);
    }

    private function postDocument(KernelBrowser $client, string $fileName): void
    {
        $payload = [
            'update_id' => 2002,
            'message' => [
                'message_id' => 77,
                'date' => 1718000000,
                'chat' => ['id' => self::CHAT_ID],
                'from' => ['id' => (int) self::TG_USER_ID, 'first_name' => 'Test'],
                'document' => ['file_id' => 'FILE123', 'file_name' => $fileName],
            ],
        ];

        $client->request(
            'POST',
            '/telegram/webhook',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret-123',
            ],
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function seedBoundUserWithAccount(?\DateTimeImmutable $financeLockBefore): void
    {
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-abcd0000bb01')
            ->withEmail('tg-cash-owner@example.test')
            ->build();
        $em->persist($owner);

        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-abcd0000bb01')
            ->withOwner($owner)
            ->build();
        if (null !== $financeLockBefore) {
            $company->setFinanceLockBefore($financeLockBefore);
        }
        $em->persist($company);
        $project = new ProjectDirection(
            '44444444-4444-4444-4444-abcd0000bb01',
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $em->persist($project);
        $em->persist($center);
        $em->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));

        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('33333333-3333-3333-3333-abcd0000bb01')
            ->forCompany($company)
            ->withCurrency('RUB')
            ->build();
        $em->persist($account);

        $bot = new TelegramBot(Uuid::uuid4()->toString(), 'TEST-TOKEN');
        $bot->setIsActive(true);
        $em->persist($bot);

        $telegramUser = new TelegramUser(Uuid::uuid4()->toString(), self::TG_USER_ID);
        $em->persist($telegramUser);

        $binding = new ClientBinding(Uuid::uuid4()->toString(), $company, $bot, $telegramUser);
        $binding->setMoneyAccount($account);
        $em->persist($binding);

        $em->flush();
    }

    private function postUpdate(KernelBrowser $client, string $text): void
    {
        $payload = [
            'update_id' => 1001,
            'message' => [
                'message_id' => 55,
                'date' => 1718000000,
                'chat' => ['id' => self::CHAT_ID],
                'from' => ['id' => (int) self::TG_USER_ID, 'first_name' => 'Test'],
                'text' => $text,
            ],
        ];

        $client->request(
            'POST',
            '/telegram/webhook',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                // Секрет из .env.test — иначе запрос отклонится как поддельный (403)
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret-123',
            ],
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     */
    private function captureTelegramCalls(KernelBrowser $client, array &$captured): void
    {
        $callable = static function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['ok' => true, 'result' => true], \JSON_THROW_ON_ERROR));
        };

        $client->getContainer()->set('http_client', new MockHttpClient($callable));
    }

    /**
     * Текст последнего sendMessage, отправленного в Telegram.
     *
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     */
    private function lastSentMessageText(array $captured): string
    {
        $text = '';
        foreach ($captured as $call) {
            if (!str_contains($call['url'], '/sendMessage')) {
                continue;
            }

            // Symfony HttpClient опцию "json" преобразует в "body" (JSON-строка) до вызова MockHttpClient
            $body = $call['options']['body'] ?? null;
            if (!is_string($body) || '' === $body) {
                continue;
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['text']) && is_string($decoded['text'])) {
                $text = $decoded['text'];
            }
        }

        self::assertNotSame('', $text, 'Ожидался хотя бы один sendMessage с текстом');

        return $text;
    }

    private function countCashTransactions(): int
    {
        $this->em()->clear();

        return (int) $this->em()->createQuery(
            'SELECT COUNT(t.id) FROM '.\App\Cash\Entity\Transaction\CashTransaction::class.' t WHERE t.deletedAt IS NULL'
        )->getSingleScalarResult();
    }
}
