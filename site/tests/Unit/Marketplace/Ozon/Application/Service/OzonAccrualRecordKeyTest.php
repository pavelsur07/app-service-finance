<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Service;

use App\Marketplace\Ozon\Application\Service\OzonAccrualRecordKey;
use PHPUnit\Framework\TestCase;

final class OzonAccrualRecordKeyTest extends TestCase
{
    private const BASE = 'ozon-accrual-A-product-0';

    public function testFreeKeyIsUsedAsIs(): void
    {
        self::assertSame(self::BASE, OzonAccrualRecordKey::resolve(self::BASE, '1', '2026-09-30', []));
    }

    public function testSameDayOtherAccrualGetsSuffixedKey(): void
    {
        $stamps = [self::BASE => ['accrualId' => '1', 'date' => '2026-09-30']];

        self::assertSame(self::BASE.'-acc2', OzonAccrualRecordKey::resolve(self::BASE, '2', '2026-09-30', $stamps));
    }

    public function testSameAccrualLegacyAndOtherDayAreSkipped(): void
    {
        self::assertNull(OzonAccrualRecordKey::resolve(self::BASE, '1', '2026-09-30', [self::BASE => ['accrualId' => '1', 'date' => '2026-09-30']]));
        self::assertNull(OzonAccrualRecordKey::resolve(self::BASE, '2', '2026-09-30', [self::BASE => ['accrualId' => null, 'date' => '2026-09-30']]));
        self::assertNull(OzonAccrualRecordKey::resolve(self::BASE, '2', '2026-09-30', [self::BASE => ['accrualId' => '1', 'date' => '2026-09-29']]));
    }

    public function testAccrualAlreadyStoredUnderItsSuffixedKeyIsNeverBookedTwice(): void
    {
        // Базовую запись удалили, а запись этого же начисления под суффиксным ключом осталась: под базовым ключом его не пишем.
        $stamps = [self::BASE.'-acc2' => ['accrualId' => '2', 'date' => '2026-09-30']];

        self::assertNull(OzonAccrualRecordKey::resolve(self::BASE, '2', '2026-09-30', $stamps));
        // Другое начисление свободный базовый ключ занять может.
        self::assertSame(self::BASE, OzonAccrualRecordKey::resolve(self::BASE, '3', '2026-09-30', $stamps));
    }

    public function testThirdAccrualGetsItsOwnSuffixAndRepeatsStayIdempotent(): void
    {
        $stamps = [
            self::BASE => ['accrualId' => '1', 'date' => '2026-09-30'],
            self::BASE.'-acc2' => ['accrualId' => '2', 'date' => '2026-09-30'],
        ];

        self::assertSame(self::BASE.'-acc3', OzonAccrualRecordKey::resolve(self::BASE, '3', '2026-09-30', $stamps));
        self::assertNull(OzonAccrualRecordKey::resolve(self::BASE, '2', '2026-09-30', $stamps));
    }

    public function testProbeKeysCoverBaseAndSuffixedForms(): void
    {
        $keys = OzonAccrualRecordKey::probeKeys([
            ['externalId' => self::BASE, 'accrualId' => '1'],
            ['externalId' => self::BASE, 'accrualId' => '2'],
        ]);

        self::assertEqualsCanonicalizing([self::BASE, self::BASE.'-acc1', self::BASE.'-acc2'], $keys);
    }
}
