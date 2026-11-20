<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\DTO;

use App\Cash\Application\DTO\CashTransactionFilters;
use PHPUnit\Framework\TestCase;

final class CashTransactionFiltersTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    public function testDrilldownFiltersAreParsed(): void
    {
        $filters = CashTransactionFilters::fromQuery([
            'categoryId' => self::A,
            'includeChildren' => '1',
            'projectIds' => self::A.','.self::B,
            'centerIds' => self::B,
        ]);

        self::assertSame('1', $filters['includeChildren']);
        self::assertSame(self::A.','.self::B, $filters['projectIds']);
        self::assertSame(self::B, $filters['centerIds']);
    }

    public function testDrilldownFiltersAreAbsentByDefault(): void
    {
        $filters = CashTransactionFilters::fromQuery([]);

        self::assertNull($filters['includeChildren']);
        self::assertNull($filters['projectIds']);
        self::assertNull($filters['centerIds']);
    }

    public function testIncludeChildrenAcceptsOnlyOne(): void
    {
        self::assertNull(CashTransactionFilters::fromQuery(['includeChildren' => 'yes'])['includeChildren']);
    }

    public function testInvalidProjectIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CashTransactionFilters::fromQuery(['projectIds' => self::A.',not-a-uuid']);
    }

    public function testInvalidCenterIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CashTransactionFilters::fromQuery(['centerIds' => "x'; DROP TABLE"]);
    }

    public function testIdListsAsArrayAreRejected(): void
    {
        $filters = CashTransactionFilters::fromQuery(['projectIds' => [self::A]]);

        self::assertNull($filters['projectIds']);
    }
}
