<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Enum\AdLoadJobStatus;
use PHPUnit\Framework\TestCase;

final class AdLoadJobStatusTest extends TestCase
{
    public function testGetLabelReturnsRussianLabels(): void
    {
        self::assertSame('Ожидает', AdLoadJobStatus::PENDING->getLabel());
        self::assertSame('Выполняется', AdLoadJobStatus::RUNNING->getLabel());
        self::assertSame('Завершён', AdLoadJobStatus::COMPLETED->getLabel());
        self::assertSame('Ошибка', AdLoadJobStatus::FAILED->getLabel());
    }

    public function testIsTerminalReturnsTrueForCompletedAndFailed(): void
    {
        self::assertTrue(AdLoadJobStatus::COMPLETED->isTerminal());
        self::assertTrue(AdLoadJobStatus::FAILED->isTerminal());
    }

    public function testIsTerminalReturnsFalseForPendingAndRunning(): void
    {
        self::assertFalse(AdLoadJobStatus::PENDING->isTerminal());
        self::assertFalse(AdLoadJobStatus::RUNNING->isTerminal());
    }

    public function testIsActiveReturnsTrueForPendingAndRunning(): void
    {
        self::assertTrue(AdLoadJobStatus::PENDING->isActive());
        self::assertTrue(AdLoadJobStatus::RUNNING->isActive());
    }

    public function testIsActiveReturnsFalseForTerminalStatuses(): void
    {
        self::assertFalse(AdLoadJobStatus::COMPLETED->isActive());
        self::assertFalse(AdLoadJobStatus::FAILED->isActive());
    }

    public function testActiveAndTerminalAreMutuallyExclusive(): void
    {
        foreach (AdLoadJobStatus::cases() as $status) {
            self::assertNotSame(
                $status->isActive(),
                $status->isTerminal(),
                sprintf('Статус %s должен быть либо активным, либо терминальным, но не обоими и не ни одним', $status->value),
            );
        }
    }
}
