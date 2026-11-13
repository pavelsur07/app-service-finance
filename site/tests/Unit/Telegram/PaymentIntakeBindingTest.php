<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PaymentIntakeBindingTest extends TestCase
{
    public function testBindingKeepsActualActorAndCanSelectCompany(): void
    {
        $binding = new ClientBinding(Uuid::uuid7()->toString(), CompanyBuilder::aCompany()->build(), $this->createStub(TelegramBot::class), $this->createStub(TelegramUser::class));
        self::assertNull($binding->getActorUserId());
        self::assertFalse($binding->isSelected());
        $binding->setActorUserId(UserBuilder::DEFAULT_USER_ID);
        $binding->select(true);
        self::assertSame(UserBuilder::DEFAULT_USER_ID, $binding->getActorUserId());
        self::assertTrue($binding->isSelected());
    }

    public function testLinkPreservesActorWithoutGuessingCompanyOwner(): void
    {
        $link = new BotLink(Uuid::uuid7()->toString(), CompanyBuilder::aCompany()->build(), $this->createStub(TelegramBot::class), 'token', 'finance', new \DateTimeImmutable('+1 hour'), UserBuilder::DEFAULT_USER_ID);
        self::assertSame(UserBuilder::DEFAULT_USER_ID, $link->getActorUserId());
    }
}
