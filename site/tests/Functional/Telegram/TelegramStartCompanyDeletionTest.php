<?php

declare(strict_types=1);

namespace App\Tests\Functional\Telegram;

use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\TelegramBot;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Удаление компании (docs/tasks/company-deletion): ссылка привязки, выданная до запроса
 * удаления, не создаёт новую привязку к помеченной компании.
 */
final class TelegramStartCompanyDeletionTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-abcd0000dd01';

    public function testLinkIssuedBeforeDeletionDoesNotBindToMarkedCompany(): void
    {
        $client = static::createClient();
        $token = $this->seedCompanyWithLink();
        $this->em()->getConnection()->executeStatement('UPDATE companies SET deletion_requested_at = now() WHERE id = ?', [self::COMPANY_ID]);
        $this->em()->clear();

        $sent = [];
        $this->captureTelegramCalls($client, $sent);
        $this->postStart($client, $token, '70001');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Компания удалена', implode("\n", $sent));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM client_bindings WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testLinkBindsWorkingCompany(): void
    {
        $client = static::createClient();
        $token = $this->seedCompanyWithLink();

        $sent = [];
        $this->captureTelegramCalls($client, $sent);
        $this->postStart($client, $token, '70002');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Привязка выполнена', implode("\n", $sent));
        self::assertSame([ClientBinding::STATUS_ACTIVE], $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM client_bindings WHERE company_id = ?', [self::COMPANY_ID]));
    }

    private function seedCompanyWithLink(): string
    {
        $em = $this->em();
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-abcd0000dd01')->withEmail('tg-start-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $bot = new TelegramBot(Uuid::uuid4()->toString(), 'TEST-TOKEN');
        $bot->setIsActive(true);
        $token = 'link'.bin2hex(random_bytes(8));
        $em->persist($owner);
        $em->persist($company);
        $em->persist($bot);
        $em->persist(new BotLink(Uuid::uuid4()->toString(), $company, $bot, $token, 'finance', new \DateTimeImmutable('+1 day')));
        $em->flush();

        return $token;
    }

    private function postStart(KernelBrowser $client, string $token, string $tgUserId): void
    {
        $client->request('POST', '/telegram/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret-123',
        ], content: json_encode([
            'update_id' => 2001,
            'message' => [
                'message_id' => 1,
                'date' => 1718000000,
                'chat' => ['id' => (int) $tgUserId],
                'from' => ['id' => (int) $tgUserId, 'first_name' => 'Test'],
                'text' => '/start '.$token,
            ],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $sent тексты sendMessage
     */
    private function captureTelegramCalls(KernelBrowser $client, array &$sent): void
    {
        $client->getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
            $body = $options['body'] ?? null;
            if (str_contains($url, '/sendMessage') && \is_string($body)) {
                $decoded = json_decode($body, true);
                $sent[] = \is_array($decoded) && \is_string($decoded['text'] ?? null) ? $decoded['text'] : '';
            }

            return new MockResponse(json_encode(['ok' => true, 'result' => true], \JSON_THROW_ON_ERROR));
        }));
    }
}
