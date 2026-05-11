<?php

declare(strict_types=1);

namespace App\Telegram\Domain\Service;

final readonly class TelegramCashTransactionExternalIdGenerator
{
    public function generate(?string $botId, string $chatId, string $messageId): string
    {
        $stableBotId = $botId ?? 'default';

        return 'telegram:'.hash('sha256', sprintf('%s|%s|%s', $stableBotId, $chatId, $messageId));
    }
}
