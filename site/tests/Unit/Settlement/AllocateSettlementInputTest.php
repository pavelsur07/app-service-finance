<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Settlement\Application\DTO\AllocateSettlementInput;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AllocateSettlementInputTest extends TestCase
{
    /** @return iterable<string,array{int}> */
    public static function invalidIds(): iterable
    {
        yield 'request' => [0];
        yield 'obligation' => [1];
        yield 'payment' => [2];
    }

    #[DataProvider('invalidIds')]
    public function testMalformedIdsFailBeforeDatabaseAccess(int $index): void
    {
        $ids = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        $ids[$index] = 'invalid';
        $this->expectException(\DomainException::class);
        new AllocateSettlementInput($ids[0], $ids[1], $ids[2], Money::fromString('0.01', 'RUB'), new \DateTimeImmutable('2026-11-01'));
    }
}
