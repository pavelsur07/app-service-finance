<?php

declare(strict_types=1);

namespace App\Tests\Telegram\Functional\Admin;

use App\Telegram\Entity\TelegramBot;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stage 1 (TG-WEBHOOK-DDS-FIX): URL вебхука берётся из конфигурации (TELEGRAM_WEBHOOK_URL),
 * а не из захардкоженной константы. В .env.test задано https://tg.example.test/telegram/webhook.
 */
final class TelegramBotWebhookSetTest extends WebTestCaseBase
{
    private const ADMIN_ID = '22222222-2222-2222-2222-abcd0000aa01';
    private const EXPECTED_WEBHOOK_URL = 'https://tg.example.test/telegram/webhook';

    public function testWebhookSetUsesConfiguredUrl(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->seedActiveBot('TEST-TOKEN-123');

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $captured */
        $captured = [];
        $this->setHttpClient($client, $captured, [
            new MockResponse(json_encode(['ok' => true, 'result' => true], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'ok' => true,
                'result' => ['url' => self::EXPECTED_WEBHOOK_URL, 'pending_update_count' => 0],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('tg-admin@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $this->em()->persist($admin);
        $this->em()->flush();

        // Маршрут /admin обслуживает отдельный firewall "admin" (security.yaml), логинимся в него
        $client->loginUser($admin, 'admin');
        $client->request('POST', '/admin/telegram/bots/webhook-set');

        self::assertResponseRedirects();

        // Первый исходящий запрос — setWebhook на токен бота с URL из конфигурации
        self::assertNotEmpty($captured, 'setWebhook должен быть вызван');
        self::assertStringContainsString('/setWebhook', $captured[0]['url']);
        $body = $this->bodyAsString($captured[0]['options']);
        self::assertStringContainsString('tg.example.test', $body);
        self::assertStringNotContainsString('app.vashfindir.ru', $body);

        // Сохранённый в БД webhookUrl совпадает с конфигурацией
        $this->em()->clear();
        $bot = $this->em()->getRepository(TelegramBot::class)->findOneBy([]);
        self::assertInstanceOf(TelegramBot::class, $bot);
        self::assertSame(self::EXPECTED_WEBHOOK_URL, $bot->getWebhookUrl());
    }

    private function seedActiveBot(string $token): void
    {
        $bot = new TelegramBot(Uuid::uuid4()->toString(), $token);
        $bot->setIsActive(true);
        $this->em()->persist($bot);
        $this->em()->flush();
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     * @param list<MockResponse>                                                      $responses
     */
    private function setHttpClient(KernelBrowser $client, array &$captured, array $responses): void
    {
        $i = 0;
        $callable = static function (string $method, string $url, array $options) use ($responses, &$i, &$captured): ResponseInterface {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (!isset($responses[$i])) {
                throw new \LogicException(sprintf('MockHttpClient: нет ответа для запроса #%d (%s %s)', $i + 1, $method, $url));
            }

            return $responses[$i++];
        };

        $client->getContainer()->set('http_client', new MockHttpClient($callable));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bodyAsString(array $options): string
    {
        $body = $options['body'] ?? '';

        if (is_string($body)) {
            return $body;
        }

        if (is_iterable($body)) {
            $parts = [];
            foreach ($body as $chunk) {
                $parts[] = is_string($chunk) ? $chunk : '';
            }

            return implode('', $parts);
        }

        return '';
    }
}
