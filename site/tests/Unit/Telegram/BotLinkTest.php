<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Company\Entity\Company;
use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\TelegramBot;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BotLinkTest extends TestCase
{
    public function testIsUsedAndMarkUsed(): void
    {
        $company = $this->createStub(Company::class);
        $bot = $this->createStub(TelegramBot::class);

        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT1800S'));
        $entity = new BotLink(Uuid::uuid7()->toString(), $company, $bot, 'token', 'finance', $expiresAt);

        self::assertFalse($entity->isUsed());
        $entity->markUsed();
        self::assertTrue($entity->isUsed());
    }

    public function testIsExpiredWithLeeway(): void
    {
        $company = $this->createStub(Company::class);
        $bot = $this->createStub(TelegramBot::class);

        $now = new \DateTimeImmutable();
        $entity = new BotLink(
            Uuid::uuid7()->toString(),
            $company,
            $bot,
            'token',
            'finance',
            $now->modify('-10 seconds'),
        );

        self::assertTrue($entity->isExpired($now));
        self::assertFalse($entity->isExpired($now, 20));
        self::assertTrue($entity->isExpired($now->modify('+11 seconds'), 20));
    }
}
