<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Telegram\Domain\Service\TelegramPaymentIntakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramPaymentIntakeSourceTest extends TestCase
{
    #[DataProvider('historicalIdentities')]
    public function testHistoricalSourceAndReplyIdentityBytesArePreserved(string $botId, string $chatId, string $messageId, string $sourceKey, string $replyKey): void
    {
        self::assertSame($sourceKey, TelegramPaymentIntakeSource::sourceKey($botId, $chatId, $messageId));
        self::assertSame($replyKey, TelegramPaymentIntakeSource::replyIdempotencyKey($botId, $chatId, $messageId));
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function historicalIdentities(): iterable
    {
        yield 'numeric bot' => ['1', '2', '3', '1/2/3', 'telegram:1:2:3'];
        yield 'uuid and signed chat' => ['019a82d5-072c-7af2-9b3a-491fa8d90511', '-200', '030', '019a82d5-072c-7af2-9b3a-491fa8d90511/-200/030', 'telegram:019a82d5-072c-7af2-9b3a-491fa8d90511:-200:030'];
        yield 'signed message retained' => ['001', '-2', '-3', '001/-2/-3', 'telegram:001:-2:-3'];
    }

    #[DataProvider('malformedIdentities')]
    public function testMalformedTelegramIdentityRetainsLegacyValidation(string $botId, string $chatId, string $messageId): void
    {
        $this->expectException(\DomainException::class);
        TelegramPaymentIntakeSource::sourceKey($botId, $chatId, $messageId);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedIdentities(): iterable
    {
        yield 'bot label' => ['legacy-bot-label', '2', '3'];
        yield 'negative bot' => ['-1', '2', '3'];
        yield 'long bot' => [str_repeat('1', 31), '2', '3'];
        yield 'chat label' => ['1', 'chat', '3'];
        yield 'long chat' => ['1', str_repeat('2', 31), '3'];
        yield 'message label' => ['1', '2', 'message'];
        yield 'long message' => ['1', '2', str_repeat('3', 31)];
        yield 'line break' => ['1', '2', "3\n"];
    }
}
