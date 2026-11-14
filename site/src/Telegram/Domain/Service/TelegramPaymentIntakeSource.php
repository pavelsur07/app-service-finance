<?php

declare(strict_types=1);

namespace App\Telegram\Domain\Service;

use Ramsey\Uuid\Uuid;

/** Historical Telegram identities must remain byte-for-byte stable across retries. */
final readonly class TelegramPaymentIntakeSource
{
    public static function sourceKey(string $botId, string $chatId, string $messageId): string
    {
        if (!Uuid::isValid($botId) && 1 !== preg_match('/^[0-9]{1,30}$/D', $botId)) {
            throw new \DomainException('Некорректный бот Telegram.');
        }
        foreach ([$chatId, $messageId] as $part) {
            if (1 !== preg_match('/^-?[0-9]{1,30}$/D', $part)) {
                throw new \DomainException('Некорректный источник Telegram.');
            }
        }

        return $botId.'/'.$chatId.'/'.$messageId;
    }

    public static function replyIdempotencyKey(string $botId, string $chatId, string $messageId): string
    {
        return 'telegram:'.$botId.':'.$chatId.':'.$messageId;
    }
}
