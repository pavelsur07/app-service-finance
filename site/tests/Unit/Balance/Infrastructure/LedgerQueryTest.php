<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Infrastructure;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Query\LedgerQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class LedgerQueryTest extends TestCase
{
    public function testStatementPreservesExactLargeTotalsAndOpening(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('isTransactionActive')->willReturn(true);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([
            ['id' => 'a', 'type' => 'asset', 'opening' => '9007199254740993', 'increase' => '7', 'decrease' => '2'],
            ['id' => 'p', 'type' => 'passive', 'opening' => '9007199254740993', 'increase' => '5', 'decrease' => '0'],
        ]);
        $result = (new LedgerQuery($db))->statement('company', '2026-01-01', '2026-01-31');
        self::assertSame('9007199254740998', $result['accounts'][0]['closing']);
        self::assertSame('0', $result['difference']);
    }

    public function testInvalidDateIsRejectedBeforeDatabaseAccess(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAllAssociative');
        $this->expectException(BalanceLedgerException::class);
        (new LedgerQuery($db))->statement('company', '2026-02-30', '2026-03-01');
    }
}
