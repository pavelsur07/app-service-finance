<?php

declare(strict_types=1);

namespace App\Tests\Telegram\Functional;

use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
