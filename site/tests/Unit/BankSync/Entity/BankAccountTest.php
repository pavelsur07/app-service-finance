<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Entity;

use App\BankSync\Entity\BankAccount;
use App\Tests\Builders\BankSync\BankAccountBuilder;
use PHPUnit\Framework\TestCase;

final class BankAccountTest extends TestCase
{
    private const MONEY_ACCOUNT_ID = '66666666-6666-7666-8666-666666666666';

    public function testNewAccountIsNotLinkedAndNotLoadable(): void
    {
        $account = BankAccountBuilder::anAccount()->build();

        self::assertNull($account->getMoneyAccountId());
        self::assertFalse($account->isLoadable());
    }

    public function testLinkedEnabledPresentAccountIsLoadable(): void
    {
        $account = BankAccountBuilder::anAccount()->linkedTo(self::MONEY_ACCOUNT_ID)->build();

        self::assertTrue($account->isLoadable());
        self::assertNotNull($account->getLinkedAt());
    }

    public function testDisabledOrMissingAccountIsNotLoadableButKeepsLink(): void
    {
        $disabled = BankAccountBuilder::anAccount()->linkedTo(self::MONEY_ACCOUNT_ID)->disabledInBank()->build();
        $missing = BankAccountBuilder::anAccount()->linkedTo(self::MONEY_ACCOUNT_ID)->missingFromBank()->build();

        self::assertFalse($disabled->isLoadable());
        self::assertFalse($missing->isLoadable());
        self::assertSame(self::MONEY_ACCOUNT_ID, $missing->getMoneyAccountId());
    }

    public function testAccountSeenAgainIsPresent(): void
    {
        $account = BankAccountBuilder::anAccount()->linkedTo(self::MONEY_ACCOUNT_ID)->missingFromBank()->build();
        $seenAt = new \DateTimeImmutable('2026-10-28 08:00:00');

        $account->observeInBank('RUB', true, '044525593', 'Новое описание', 'acc-1', $seenAt);

        self::assertTrue($account->isPresentInBank());
        self::assertTrue($account->isLoadable());
        self::assertSame('Новое описание', $account->getDescription());
        self::assertEquals($seenAt, $account->getLastSeenAt());
    }

    public function testRepeatedLinkKeepsLinkTimeAndUnlinkClearsIt(): void
    {
        $account = BankAccountBuilder::anAccount()->linkedTo(self::MONEY_ACCOUNT_ID)->build();
        $linkedAt = $account->getLinkedAt();

        $account->linkTo(self::MONEY_ACCOUNT_ID);
        self::assertSame($linkedAt, $account->getLinkedAt(), 'повтор той же привязки — без изменений');

        $account->unlink();
        self::assertNull($account->getMoneyAccountId());
        self::assertNull($account->getLinkedAt());
    }

    public function testLongBankValuesAreTruncatedToColumns(): void
    {
        $account = BankAccountBuilder::anAccount()->build();

        $account->observeInBank('RUB', true, str_repeat('1', 40), str_repeat('д', 200), str_repeat('a', 100), new \DateTimeImmutable());

        self::assertSame(20, mb_strlen((string) $account->getBic()));
        self::assertSame(128, mb_strlen((string) $account->getDescription()));
        self::assertSame(64, mb_strlen((string) $account->getExternalId()));
    }

    public function testInvalidCurrencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BankAccount(BankAccountBuilder::DEFAULT_ID, BankAccountBuilder::DEFAULT_ID, '40702810900000000001', 'rub', new \DateTimeImmutable());
    }
}
