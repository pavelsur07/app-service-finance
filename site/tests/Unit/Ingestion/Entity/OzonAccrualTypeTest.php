<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\OzonAccrualType;
use App\Tests\Builders\Ingestion\OzonAccrualTypeBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonAccrualTypeTest extends TestCase
{
    public function testConstructorNormalizesNameAndEmptyDescription(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-10-01 02:30:00');

        $type = new OzonAccrualType(127, '  AnalyticsPlus ', '   ', Uuid::uuid7()->toString(), $fetchedAt);

        self::assertTrue(Uuid::isValid($type->getId()));
        self::assertSame(127, $type->getTypeId());
        self::assertSame('AnalyticsPlus', $type->getName());
        self::assertNull($type->getDescription());
        self::assertEquals($fetchedAt, $type->getFirstSeenAt());
        self::assertFalse($type->isRemoved());
    }

    public function testRejectsNonPositiveTypeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OzonAccrualTypeBuilder::aType()->withTypeId(0)->build();
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OzonAccrualTypeBuilder::aType()->withName('  ')->build();
    }

    public function testApplyVersionReportsChangeAndKeepsFirstSeen(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->withDescription(null)->build();
        $firstSeen = $type->getFirstSeenAt();
        $rawRecordId = Uuid::uuid7()->toString();

        $changed = $type->applyVersion('LastMileCourier', 'Доставка до места выдачи', $rawRecordId, new \DateTimeImmutable('2026-10-09 02:30:00'));

        self::assertTrue($changed);
        self::assertSame('Доставка до места выдачи', $type->getDescription());
        self::assertSame($rawRecordId, $type->getLastRawRecordId());
        self::assertEquals($firstSeen, $type->getFirstSeenAt());
    }

    public function testApplySameVersionContentIsNotAChange(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->build();
        $lastRawRecordId = $type->getLastRawRecordId();
        $later = new \DateTimeImmutable('2026-10-09 02:30:00');

        $changed = $type->applyVersion('LastMileCourier', 'Доставка до места выдачи', Uuid::uuid7()->toString(), $later);

        self::assertFalse($changed);
        self::assertSame($lastRawRecordId, $type->getLastRawRecordId());
        self::assertEquals($later, $type->getVersionFetchedAt());
    }

    public function testOlderVersionCannotBeApplied(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->build();

        $this->expectException(\InvalidArgumentException::class);

        $type->applyVersion('Renamed', null, Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-10-01 02:30:00'));
    }

    public function testMarkRemovedAndRestoreByNewerVersion(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->build();
        $removedAt = new \DateTimeImmutable('2026-10-09 02:30:00');

        self::assertTrue($type->markRemoved(Uuid::uuid7()->toString(), $removedAt));
        self::assertTrue($type->isRemoved());
        self::assertEquals($removedAt, $type->getRemovedAt());
        self::assertFalse($type->markRemoved(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-10-10 02:30:00')));

        self::assertTrue($type->applyVersion('LastMileCourier', 'Доставка до места выдачи', Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-10-11 02:30:00')));
        self::assertFalse($type->isRemoved());
    }

    public function testOlderVersionDoesNotMarkRemoved(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->build();

        self::assertFalse($type->markRemoved(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-10-01 02:30:00')));
        self::assertFalse($type->isRemoved());
    }
}
