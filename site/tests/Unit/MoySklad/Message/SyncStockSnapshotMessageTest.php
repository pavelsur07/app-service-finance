<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Message;

use App\MoySklad\Message\SyncStockSnapshotMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncStockSnapshotMessageTest extends TestCase
{
    public function testCarriesOnlySafeIdentifiersAndAttempt(): void
    {
        $message = new SyncStockSnapshotMessage('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333');

        self::assertSame(['companyId', 'connectionId', 'attempt'], array_keys(get_object_vars($message)));
        self::assertSame(0, $message->attempt);
    }

    #[DataProvider('invalidAttempts')]
    public function testRejectsAttemptOutsideZeroToThree(int $attempt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SyncStockSnapshotMessage('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', $attempt);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAttempts(): iterable
    {
        yield 'negative' => [-1];
        yield 'above maximum' => [4];
    }
}
