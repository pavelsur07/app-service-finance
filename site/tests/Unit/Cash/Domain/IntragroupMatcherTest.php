<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Domain;

use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Domain\Policy\IntragroupMatcher;
use App\Cash\Enum\Transaction\CashDirection;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class IntragroupMatcherTest extends TestCase
{
    public function testMatchesFiveReciprocalPairsFromTheReportFixture(): void
    {
        $out = CashDirection::OUTFLOW;
        $in = CashDirection::INFLOW;
        $rows = [
            $this->leg('01', 'A', 'B', $out, 2, '100000.00'),
            $this->leg('04', 'A', 'B', $out, 10, '450000.00'),
            $this->leg('05', 'A', 'external', $in, 10, '2500.00'),
            $this->leg('06', 'A', 'external', $in, 12, '50000.00'),
            $this->leg('12', 'A', 'B', $in, 10, '260000.00'),
            $this->leg('13', 'A', 'C', $out, 10, '57000.00'),
            $this->leg('14', 'A', 'A', $out, 16, '2500.00'),
            $this->leg('15', 'A', 'B', $in, 17, '50000.00'),
            $this->leg('19', 'B', 'A', $in, 2, '100000.00'),
            $this->leg('20', 'B', 'A', $in, 10, '450000.00'),
            $this->leg('21', 'B', 'A', $out, 10, '260000.00'),
            $this->leg('22', 'B', 'A', $in, 13, '2600000.00'),
            $this->leg('23', 'B', 'A', $in, 17, '150000.00'),
            $this->leg('24', 'B', 'A', $out, 17, '50000.00'),
            $this->leg('30', 'C', 'A', $in, 10, '57000.00'),
        ];
        $expected = ['tx01' => 'tx19', 'tx04' => 'tx20', 'tx13' => 'tx30', 'tx21' => 'tx12', 'tx24' => 'tx15'];
        self::assertSame($expected, (new IntragroupMatcher())->match($rows));
        self::assertSame($expected, (new IntragroupMatcher())->match(array_reverse($rows)));
    }

    public function testClosestDatesThenIdsWinAndRejectedEdgeLeavesTheLegsFree(): void
    {
        $rows = [
            $this->leg('old', 'A', 'B', CashDirection::OUTFLOW, 1),
            $this->leg('new', 'A', 'B', CashDirection::OUTFLOW, 2),
            $this->leg('b', 'B', 'A', CashDirection::INFLOW, 3),
            $this->leg('a', 'B', 'A', CashDirection::INFLOW, 3),
        ];
        $matcher = new IntragroupMatcher();
        self::assertSame(['txnew' => 'txa', 'txold' => 'txb'], $matcher->match($rows));
        self::assertSame(['txnew' => 'txb', 'txold' => 'txa'], $matcher->match($rows, ['txnew' => ['txa' => true]]));
    }

    public function testManyEqualPaymentsOnOneDayRemainOneToOne(): void
    {
        $rows = [];
        for ($index = 0; $index < 1000; ++$index) {
            $rows[] = $this->leg('out'.$index, 'A', 'B', CashDirection::OUTFLOW, 1);
            $rows[] = $this->leg('in'.$index, 'B', 'A', CashDirection::INFLOW, 1);
        }
        $pairs = (new IntragroupMatcher())->match($rows);
        self::assertCount(1000, $pairs);
        self::assertCount(1000, array_unique($pairs));
        self::assertSame('txin999', $pairs['txout999']);
    }

    public function testCalendarWindowAmountCurrencyAndBothTaxIds(): void
    {
        $out = $this->leg('out', 'A', 'B', CashDirection::OUTFLOW, 4);
        $matcher = new IntragroupMatcher();
        foreach ([
            $this->leg('early', 'B', 'A', CashDirection::INFLOW, 3),
            $this->leg('late', 'B', 'A', CashDirection::INFLOW, 10),
            $this->leg('cent', 'B', 'A', CashDirection::INFLOW, 4, '10.01'),
            $this->leg('usd', 'B', 'A', CashDirection::INFLOW, 4, '10.00', 'USD'),
            $this->leg('wrong', 'B', 'C', CashDirection::INFLOW, 4),
            $this->leg('unknown', 'B', null, CashDirection::INFLOW, 4),
            $this->leg('third', 'C', 'A', CashDirection::INFLOW, 4),
        ] as $invalid) {
            self::assertSame([], $matcher->match([$out, $invalid]), $invalid->id);
        }
        $last = $this->leg('last', 'B', 'A', CashDirection::INFLOW, 9);
        self::assertSame(['txout' => 'txlast'], $matcher->match([$out, $last]));
        self::assertSame([], $matcher->match([
            $this->leg('self-out', 'A', 'A', CashDirection::OUTFLOW, 4),
            $this->leg('self-in', 'A', 'A', CashDirection::INFLOW, 4),
        ]));
    }

    private function leg(string $id, string $company, ?string $peer, CashDirection $direction, int $day, string $amount = '10.00', string $currency = 'RUB'): IntragroupTransaction
    {
        return new IntragroupTransaction('tx'.$id, $company, 'inn'.$company, null === $peer ? null : 'inn'.$peer,
            $direction, new \DateTimeImmutable(sprintf('2026-11-%02d', $day)), Money::fromString($amount, $currency));
    }
}
