<?php

declare(strict_types=1);

namespace App\Telegram\Facade;

use App\Telegram\Entity\ClientBinding;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TelegramFacade implements TelegramFacadeInterface
{
    /** Предел подписи к файлу у Telegram. */
    private const CAPTION_LIMIT = 1024;

    public function __construct(
        private TelegramBotRepository $bots,
        private Connection $connection,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $telegramApiBaseUrl,
    ) {
    }

    public function sendDocumentToCompany(string $companyId, string $path, string $fileName, string $caption): array
    {
        $bot = $this->bots->findActiveBot();
        if (null === $bot) {
            return ['sent' => 0, 'failed' => 0];
        }
        $chatIds = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT u.tg_user_id FROM client_bindings b JOIN telegram_users u ON u.id = b.telegram_user_id
             WHERE b.company_id = :company AND b.bot_id = :bot AND b.status = :active ORDER BY u.tg_user_id',
            ['company' => $companyId, 'bot' => $bot->getId(), 'active' => ClientBinding::STATUS_ACTIVE],
        ));

        $sent = $failed = 0;
        foreach ($chatIds as $chatId) {
            $form = new FormDataPart([
                'chat_id' => $chatId,
                'caption' => mb_substr($caption, 0, self::CAPTION_LIMIT),
                'document' => DataPart::fromPath($path, $fileName),
            ]);
            $started = microtime(true);
            try {
                $response = $this->httpClient->request('POST', sprintf('%s/bot%s/sendDocument', rtrim($this->telegramApiBaseUrl, '/'), $bot->getToken()), [
                    'headers' => $form->getPreparedHeaders()->toArray(),
                    'body' => $form->bodyToIterable(),
                ]);
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $status = 0;
                $this->logger->warning('Telegram: sendDocument failed.', ['companyId' => $companyId, 'exception' => $e::class]);
            }
            $this->logger->info('Telegram: sendDocument.', ['companyId' => $companyId, 'method' => 'POST', 'status' => $status, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
            200 === $status ? ++$sent : ++$failed;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
