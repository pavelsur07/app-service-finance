<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service;

use App\Cash\Application\Service\DebouncedRangeEnqueuer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class DebouncedRangeEnqueuerTest extends TestCase
{
    #[DataProvider('acquireResults')]
    public function testReturnsLockAcquisitionResult(bool $acquired): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($acquired);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())
            ->method('createLock')
            ->with(
                'auto_rules.enqueue.company-1.2024-01-15.account-1',
                120,
                false,
            )
            ->willReturn($lock);

        $enqueuer = new DebouncedRangeEnqueuer($lockFactory);

        self::assertSame($acquired, $enqueuer->shouldEnqueueCompanyDay(
            'company-1',
            new \DateTimeImmutable('2024-01-15 12:00:00'),
            'account-1',
        ));
    }

    public static function acquireResults(): iterable
    {
        yield 'first event' => [true];
        yield 'duplicate event' => [false];
    }
}
