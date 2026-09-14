<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Enum\ConnectionCheckStatus;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use PHPUnit\Framework\TestCase;

final class MoySkladConnectionTest extends TestCase
{
    public function testExistingActiveConnectionIsUnverified(): void
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        self::assertTrue($connection->isActive());
        self::assertFalse($connection->isVerified());
        self::assertNull($connection->getAccountId());
        self::assertSame(ConnectionCheckStatus::UNVERIFIED, $connection->getCheckStatus());
        self::assertSame(1, $connection->getVersion());
    }

    public function testAccountBindingIsImmutable(): void
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount('11111111-1111-4111-8111-111111111111');
        $connection->bindAccount('11111111-1111-4111-8111-111111111111');
        self::assertTrue($connection->isVerified());
        self::assertSame('11111111-1111-4111-8111-111111111111', $connection->getAccountId());
        $this->expectException(\LogicException::class);
        $connection->bindAccount('22222222-2222-4222-8222-222222222222');
    }

    public function testFailedCheckPreservesSuccessfulTimestamp(): void
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $success = new \DateTimeImmutable('2026-09-14 10:00:00');
        $failure = $success->modify('+1 minute');
        self::assertNull($connection->getLastCheckedAt());
        self::assertNull($connection->getLastSuccessfulCheckAt());
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, $success);
        $connection->recordCheck(ConnectionCheckStatus::INVALID_TOKEN, $failure);
        self::assertSame($success, $connection->getLastSuccessfulCheckAt());
        self::assertSame($failure, $connection->getLastCheckedAt());
        self::assertSame(ConnectionCheckStatus::INVALID_TOKEN, $connection->getCheckStatus());
    }
}
