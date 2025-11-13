<?php

declare(strict_types=1);

namespace App\Tests\Telegram\Unit;

use App\Entity\Company;
use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\TelegramBot;
use PHPUnit\Framework\TestCase;

final class BotLinkTest extends TestCase
{
    public function testIsUsedAndMarkUsed(): void
    {
        $company = $this->createStub(Company::class);
        $bot = $this->createStub(TelegramBot::class);

        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT1800S'));
        $entity = new BotLink($company, $bot, 'token', 'finance', $expiresAt);

        self::assertFalse($entity->isUsed());
        $entity->markUsed();
        self::assertTrue($entity->isUsed());
    }

    public function testIsExpiredWithLeeway(): void
    {
        $company = $this->createStub(Company::class);
        $bot = $this->createStub(TelegramBot::class);

        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval('PT60S'));
        $entity = new BotLink($company, $bot, 'token', 'finance', $expiresAt);

        // Без ливея — не истёк
        self::assertFalse($entity->isExpired($now));

        // С ливеем 120с — считается истёкшим
        self::assertTrue($entity->isExpired($now, 120));
    }
}
