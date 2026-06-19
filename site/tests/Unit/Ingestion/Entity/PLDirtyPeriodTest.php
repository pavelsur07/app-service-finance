<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PLDirtyPeriodTest extends TestCase
{
    public function testConstructorSetsPendingPeriod(): void
    {
        $period = $this->newPeriod();

        self::assertTrue(Uuid::isValid($period->getId()));
        self::assertInstanceOf(TenantOwnedInterface::class, $period);
        self::assertSame(PLDirtyPeriodStatus::PENDING, $period->getStatus());
        self::assertSame(PLDirtyPeriodReason::INGEST, $period->getReason());
        self::assertSame(2026, $period->getPeriodYear());
        self::assertSame(2, $period->getPeriodMonth());
        self::assertSame('ozon:shop-1', $period->getShopRef());
        self::assertSame(0, $period->getAttempts());
        self::assertNull($period->getRebuiltAt());
        self::assertNull($period->getLastError());
    }

    public function testConstructorRejectsInvalidPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PLDirtyPeriod(
            companyId: Uuid::uuid7()->toString(),
            periodYear: 2019,
            periodMonth: 13,
            shopRef: '',
            reason: PLDirtyPeriodReason::INGEST,
        );
    }

    public function testSuccessfulRebuildTransitionSetsAuditFields(): void
    {
        $period = $this->newPeriod();
        $rebuiltAt = new \DateTimeImmutable('2026-06-18 10:00:00');

        $period->markRebuilding();
        $period->markDone($rebuiltAt);

        self::assertSame(PLDirtyPeriodStatus::DONE, $period->getStatus());
        self::assertSame(1, $period->getAttempts());
        self::assertSame($rebuiltAt, $period->getRebuiltAt());
        self::assertNull($period->getLastError());
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $period = $this->newPeriod();

        $this->expectException(\DomainException::class);

        $period->markDone();
    }

    public function testFailedAndBlockedPeriodsCanBeReopened(): void
    {
        $failed = $this->newPeriod();
        $failed->markRebuilding();
        $failed->markFailed('temporary error');
        $failed->reopen();

        self::assertSame(PLDirtyPeriodStatus::PENDING, $failed->getStatus());
        self::assertNull($failed->getLastError());

        $blocked = $this->newPeriod();
        $blocked->markBlockedByClose('closed month');
        $blocked->reopen();

        self::assertSame(PLDirtyPeriodStatus::PENDING, $blocked->getStatus());
        self::assertNull($blocked->getLastError());
    }

    private function newPeriod(): PLDirtyPeriod
    {
        return new PLDirtyPeriod(
            companyId: Uuid::uuid7()->toString(),
            periodYear: 2026,
            periodMonth: 2,
            shopRef: 'ozon:shop-1',
            reason: PLDirtyPeriodReason::INGEST,
        );
    }
}
