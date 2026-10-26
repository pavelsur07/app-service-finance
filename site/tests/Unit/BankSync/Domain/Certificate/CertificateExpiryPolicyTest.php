<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Domain\Certificate;

use App\BankSync\Domain\Certificate\CertificateExpiryPolicy;
use App\BankSync\Enum\CertificateExpiryLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CertificateExpiryPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, CertificateExpiryLevel, int}>
     */
    public static function expiries(): iterable
    {
        yield 'year left' => ['+365 days', CertificateExpiryLevel::OK, 365];
        yield '31 days left' => ['+31 days', CertificateExpiryLevel::OK, 31];
        yield '30 days left' => ['+30 days', CertificateExpiryLevel::WARNING, 30];
        yield '15 days left' => ['+15 days', CertificateExpiryLevel::WARNING, 15];
        yield '14 days left' => ['+14 days', CertificateExpiryLevel::ERROR, 14];
        yield 'hours left' => ['+3 hours', CertificateExpiryLevel::ERROR, 0];
        yield 'expires now' => ['+0 seconds', CertificateExpiryLevel::ERROR, 0];
        yield 'expired' => ['-2 days', CertificateExpiryLevel::ERROR, -2];
    }

    #[DataProvider('expiries')]
    public function testLevelByDaysLeft(string $notAfter, CertificateExpiryLevel $level, int $daysLeft): void
    {
        $policy = new CertificateExpiryPolicy();
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');

        self::assertSame($level, $policy->level($now->modify($notAfter), $now));
        self::assertSame($daysLeft, $policy->daysLeft($now->modify($notAfter), $now));
    }
}
