<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\ExpiryNotice;
use App\Billing\Enum\AccessStatus;
use App\Billing\Enum\TariffCode;
use App\Billing\Facade\DTO\BillingAccessDTO;
use PHPUnit\Framework\TestCase;

final class ExpiryNoticeTest extends TestCase
{
    public function testNoNoticeForPerpetualMissingOrDistantEnd(): void
    {
        self::assertNull(ExpiryNotice::forAccess(new BillingAccessDTO(AccessStatus::ACTIVE, TariffCode::FRIEND, 'Друг', null, null)));
        self::assertNull(ExpiryNotice::forAccess(new BillingAccessDTO(AccessStatus::NONE, null, null, null, null)));
        // За 8 дней до «оплачено по» (daysLeft = 9, считая сегодня) — ещё рано.
        self::assertNull(ExpiryNotice::forAccess($this->access(AccessStatus::ACTIVE, 9)));
    }

    public function testEndingWithinNoticeDays(): void
    {
        // За 7 дней до «оплачено по» — daysLeft = 8 (сегодня тоже оплачен): баннер уже есть, как и письмо.
        $notice = ExpiryNotice::forAccess($this->access(AccessStatus::ACTIVE, ExpiryNotice::NOTICE_DAYS + 1));

        self::assertSame(ExpiryNotice::KIND_ENDING, $notice?->kind);
        self::assertSame(8, $notice->daysLeft);
        self::assertFalse($notice->trial);
        self::assertSame('2027-01-31', $notice->paidUntil->format('Y-m-d'));
    }

    public function testTrialEnding(): void
    {
        self::assertSame(ExpiryNotice::KIND_TRIAL_ENDING, ExpiryNotice::forAccess($this->access(AccessStatus::ACTIVE, 1, trial: true))?->kind);
    }

    public function testGraceAndReadOnly(): void
    {
        $grace = ExpiryNotice::forAccess($this->access(AccessStatus::GRACE, 0));
        self::assertSame(ExpiryNotice::KIND_GRACE, $grace?->kind);
        self::assertSame('2027-02-05', $grace->graceUntil->format('Y-m-d'));

        self::assertSame(ExpiryNotice::KIND_READ_ONLY, ExpiryNotice::forAccess($this->access(AccessStatus::READ_ONLY, 0))?->kind);
        self::assertTrue(ExpiryNotice::forAccess($this->access(AccessStatus::GRACE, 0, trial: true))?->trial, 'истёкший пробный — тексты без «оплаченного»');
    }

    private function access(AccessStatus $status, int $daysLeft, bool $trial = false): BillingAccessDTO
    {
        return new BillingAccessDTO($status, TariffCode::BASE, 'База', new \DateTimeImmutable('2027-01-31'), $daysLeft, $trial);
    }
}
