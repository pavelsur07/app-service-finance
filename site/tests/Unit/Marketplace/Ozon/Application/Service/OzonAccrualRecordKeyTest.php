<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Service;

use App\Marketplace\Ozon\Application\Service\OzonAccrualRecordKey;
use PHPUnit\Framework\TestCase;

final class OzonAccrualRecordKeyTest extends TestCase
{
    private const BASE = 'ozon-accrual-A-product-0';
    private const DAY = '2026-09-30';

    public function testFreeKeyIsUsedAsIs(): void
    {
        self::assertSame(['action' => 'insert', 'key' => self::BASE], OzonAccrualRecordKey::decide(self::BASE, '1', self::DAY, '3800.00', []));
    }

    public function testSameDayOtherStampedAccrualGetsSuffixedKey(): void
    {
        $stamps = [self::BASE => $this->stamp('1', '3800.00')];

        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-acc2'], OzonAccrualRecordKey::decide(self::BASE, '2', self::DAY, '3800.00', $stamps));
    }

    public function testSameAccrualIsSkippedOnAnyDay(): void
    {
        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '1', self::DAY, '3800.00', [self::BASE => $this->stamp('1', '3800.00')])['action']);
        // Тот же accrual_id, но Ozon сменил дату начисления: повторно не пишем.
        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '1', self::DAY, '3800.00', [self::BASE => $this->stamp('1', '3800.00', '2026-09-29')])['action']);
    }

    public function testRebookedAccrualOfAnotherDayGetsSuffixedKey(): void
    {
        // Переоформление 01.10.2026: продажа 19.09 уже записана, Ozon прислал новое начисление того же отправления.
        $stamps = [self::BASE => $this->stamp('1', '1187.00', '2026-09-19')];
        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-acc2'], OzonAccrualRecordKey::decide(self::BASE, '2', '2026-10-01', '1187.00', $stamps));

        // Историческая запись без метки: как у Сухоносова, продажа 19.09 создана до появления метки.
        $legacy = [self::BASE => $this->stamp(null, '1187.00', '2026-09-19')];
        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-acc2'], OzonAccrualRecordKey::decide(self::BASE, '2', '2026-10-01', '1187.00', $legacy));
    }

    public function testRebookedAccrualIsBookedOnceOnRerun(): void
    {
        $stamps = [
            self::BASE => $this->stamp(null, '1187.00', '2026-09-19'),
            self::BASE.'-acc2' => $this->stamp('2', '1187.00', '2026-10-01'),
        ];

        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '2', '2026-10-01', '1187.00', $stamps)['action']);
    }

    public function testLegacyRowWithEqualAmountBelongsToThisAccrual(): void
    {
        $stamps = [self::BASE => $this->stamp(null, '9242.00')];

        self::assertSame(['action' => 'claim_legacy', 'key' => self::BASE], OzonAccrualRecordKey::decide(self::BASE, '2', self::DAY, '9242.00', $stamps));
        // Сумма записана без хвостовых нулей — всё равно равна.
        self::assertSame('claim_legacy', OzonAccrualRecordKey::decide(self::BASE, '2', self::DAY, '9242.00', [self::BASE => $this->stamp(null, '9242.0')])['action']);
    }

    public function testLegacyRowWithDifferentAmountMeansAnotherAccrual(): void
    {
        $stamps = [self::BASE => $this->stamp(null, '9242.00')];

        // Случай «ИП Лазарева»: историческая запись 9 242 ₽, второе начисление 16 346 ₽ того же дня.
        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-acc1'], OzonAccrualRecordKey::decide(self::BASE, '1', self::DAY, '16346.00', $stamps));
    }

    public function testLegacyRowIsClaimedOnceAndTheTwinGetsItsOwnKey(): void
    {
        // Случай «Вумджой»: историческая запись 3 800 ₽, два начисления по 3 800 ₽.
        $stamps = [self::BASE => $this->stamp(null, '3800.00')];

        $first = OzonAccrualRecordKey::decide(self::BASE, 'A', self::DAY, '3800.00', $stamps);
        self::assertSame('claim_legacy', $first['action']);

        $stamps[self::BASE] = $this->stamp('A', '3800.00');
        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-accB'], OzonAccrualRecordKey::decide(self::BASE, 'B', self::DAY, '3800.00', $stamps));
    }

    public function testAccrualAlreadyStoredUnderItsSuffixedKeyIsNeverBookedTwice(): void
    {
        $stamps = [self::BASE.'-acc2' => $this->stamp('2', '3800.00')];

        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '2', self::DAY, '3800.00', $stamps)['action']);
        self::assertSame('insert', OzonAccrualRecordKey::decide(self::BASE, '3', self::DAY, '3800.00', $stamps)['action']);
    }

    public function testRerunIsIdempotent(): void
    {
        $stamps = [
            self::BASE => $this->stamp('1', '3800.00'),
            self::BASE.'-acc2' => $this->stamp('2', '3800.00'),
        ];

        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '1', self::DAY, '3800.00', $stamps)['action']);
        self::assertSame('skip', OzonAccrualRecordKey::decide(self::BASE, '2', self::DAY, '3800.00', $stamps)['action']);
        self::assertSame(['action' => 'insert', 'key' => self::BASE.'-acc3'], OzonAccrualRecordKey::decide(self::BASE, '3', self::DAY, '3800.00', $stamps));
    }

    public function testProbeKeysCoverBaseAndSuffixedForms(): void
    {
        $keys = OzonAccrualRecordKey::probeKeys([
            ['externalId' => self::BASE, 'accrualId' => '1'],
            ['externalId' => self::BASE, 'accrualId' => '2'],
        ]);

        self::assertEqualsCanonicalizing([self::BASE, self::BASE.'-acc1', self::BASE.'-acc2'], $keys);
    }

    /**
     * @return array{accrualId: ?string, date: string, amount: string}
     */
    private function stamp(?string $accrualId, string $amount, string $date = self::DAY): array
    {
        return ['accrualId' => $accrualId, 'date' => $date, 'amount' => $amount];
    }
}
