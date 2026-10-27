<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Domain\Consent;

use App\BankSync\Domain\Consent\BankConsent;
use App\BankSync\Domain\Consent\ConsentDecision;
use App\BankSync\Domain\Consent\ConsentExpiryPolicy;
use App\BankSync\Enum\ConsentState;
use PHPUnit\Framework\TestCase;

final class ConsentExpiryPolicyTest extends TestCase
{
    private const SUB = 'sub-1';

    public function testNoConsentsIsNotFound(): void
    {
        $decision = $this->decide([]);

        self::assertSame(ConsentState::NOT_FOUND, $decision->state);
        self::assertNull($decision->expiresOn);
    }

    public function testActiveConsentGivesItsLastDay(): void
    {
        $decision = $this->decide([$this->consent(true, '2027-06-01')]);

        self::assertSame(ConsentState::ACTIVE, $decision->state);
        self::assertSame('2027-06-01', $decision->expiresOn?->format('Y-m-d'));
        self::assertFalse($decision->expiresSoon);
    }

    public function testEarliestActiveConsentWinsAndInactiveOrPastAreIgnored(): void
    {
        $decision = $this->decide([
            $this->consent(true, '2027-06-01'),
            $this->consent(true, '2027-03-01'),
            $this->consent(false, '2026-11-01'),
            $this->consent(true, '2026-10-26'),
        ]);

        self::assertSame('2027-03-01', $decision->expiresOn?->format('Y-m-d'));
    }

    public function testLastDayIsStillActive(): void
    {
        $decision = $this->decide([$this->consent(true, '2026-10-27')]);

        self::assertSame(ConsentState::ACTIVE, $decision->state);
        self::assertTrue($decision->expiresSoon);
    }

    public function testWarningBoundaryIsThirtyDays(): void
    {
        self::assertTrue($this->decide([$this->consent(true, '2026-11-26')])->expiresSoon, '30 дней');
        self::assertFalse($this->decide([$this->consent(true, '2026-11-27')])->expiresSoon, '31 день');
    }

    public function testOnlyInactiveOrExpiredIsExpired(): void
    {
        self::assertSame(ConsentState::EXPIRED, $this->decide([$this->consent(false, '2027-06-01')])->state);
        self::assertSame(ConsentState::EXPIRED, $this->decide([$this->consent(true, '2026-10-26')])->state);
    }

    public function testTimeZoneOfTodayDoesNotShiftTheDay(): void
    {
        // 23:30 по Москве 27.10 — это 20:30 UTC того же дня; согласие до 27.10 ещё действует.
        $today = new \DateTimeImmutable('2026-10-27 23:30:00', new \DateTimeZone('Europe/Moscow'));

        $decision = (new ConsentExpiryPolicy())->decide([$this->consent(true, '2026-10-27')], $today);

        self::assertSame(ConsentState::ACTIVE, $decision->state);
    }

    /**
     * @param list<BankConsent> $consents
     */
    private function decide(array $consents): ConsentDecision
    {
        return (new ConsentExpiryPolicy())->decide($consents, new \DateTimeImmutable('2026-10-27 10:00:00'));
    }

    private function consent(bool $isActive, string $expiresOn): BankConsent
    {
        return new BankConsent(self::SUB, $isActive, new \DateTimeImmutable($expiresOn));
    }
}
