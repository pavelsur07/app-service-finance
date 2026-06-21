<?php

declare(strict_types=1);

namespace App\Tests\Telegram\Functional;

use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 3 (TG-WEBHOOK-DDS-FIX): защита публичного вебхука по secret_token.
 * В .env.test задан TELEGRAM_WEBHOOK_SECRET=test-secret-123.
 */
final class TelegramWebhookSecretTokenTest extends WebTestCaseBase
{
    private const SECRET = 'test-secret-123';

    public function testMissingSecretIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->postUpdate($client, null);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWrongSecretIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $this->postUpdate($client, 'wrong-secret');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testValidSecretPassesGate(): void
    {
        $client = static::createClient();
        $this->resetDb();

        // Корректный секрет, но активного бота нет → проходит проверку и доходит до выбора бота (200)
        $this->postUpdate($client, self::SECRET);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('inactive_bot', $data['status'] ?? null);
    }

    private function postUpdate(KernelBrowser $client, ?string $secret): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $secret) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
        }

        $payload = [
            'update_id' => 2001,
            'message' => [
                'message_id' => 7,
                'date' => 1718000000,
                'chat' => ['id' => 999],
                'from' => ['id' => 999, 'first_name' => 'Probe'],
                'text' => 'ping',
            ],
        ];

        $client->request('POST', '/telegram/webhook', [], [], $server, json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
