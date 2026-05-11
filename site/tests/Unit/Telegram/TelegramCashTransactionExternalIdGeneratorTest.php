<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Telegram\Domain\Service\TelegramCashTransactionExternalIdGenerator;
use PHPUnit\Framework\TestCase;

final class TelegramCashTransactionExternalIdGeneratorTest extends TestCase
{
    public function testSameIdentityProducesSameExternalId(): void
    {
        $generator = new TelegramCashTransactionExternalIdGenerator();

        self::assertSame(
            $generator->generate('bot-1', 'chat-1', 'msg-1'),
            $generator->generate('bot-1', 'chat-1', 'msg-1'),
        );
    }

    public function testDifferentMessageIdProducesDifferentExternalId(): void
    {
        $generator = new TelegramCashTransactionExternalIdGenerator();

        self::assertNotSame(
            $generator->generate('bot-1', 'chat-1', 'msg-1'),
            $generator->generate('bot-1', 'chat-1', 'msg-2'),
        );
    }

    public function testExternalIdLengthDoesNotExceed128(): void
    {
        $generator = new TelegramCashTransactionExternalIdGenerator();

        self::assertLessThanOrEqual(128, mb_strlen($generator->generate('bot-1', 'chat-1', 'msg-1')));
    }

    public function testFallbackBotIdDefaultIsUsedWhenBotIdIsNull(): void
    {
        $generator = new TelegramCashTransactionExternalIdGenerator();

        self::assertSame(
            'telegram:'.hash('sha256', 'default|chat-1|msg-1'),
            $generator->generate(null, 'chat-1', 'msg-1'),
        );
    }
}
