<?php

declare(strict_types=1);

namespace App\Finance\Controller\Api\BalanceDynamics;

use App\Shared\Domain\ValueObject\Money;

final readonly class BalanceDynamicsResponse
{
    /**
     * @param list<array{
     *     date:string,
     *     balance:string,
     *     belowMinimum:bool,
     *     flows:array{operating:string,financing:string,investing:string}
     * }> $points
     */
    public function __construct(
        private int $periodDays,
        private \DateTimeImmutable $from,
        private \DateTimeImmutable $to,
        private string $currency,
        private ?Money $minimumBalance,
        private array $points,
    ) {
    }

    /**
     * @return array{
     *     period:array{days:int,from:string,to:string},
     *     currency:string,
     *     minimum_balance:?array{amount:string,currency:string},
     *     points:list<array{
     *         date:string,
     *         balance:string,
     *         below_minimum:bool,
     *         flows:array{operating:string,financing:string,investing:string}
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'period' => [
                'days' => $this->periodDays,
                'from' => $this->from->format('Y-m-d'),
                'to' => $this->to->format('Y-m-d'),
            ],
            'currency' => $this->currency,
            'minimum_balance' => null === $this->minimumBalance ? null : [
                'amount' => $this->minimumBalance->toDecimalString(),
                'currency' => $this->minimumBalance->currency(),
            ],
            'points' => array_map(static fn (array $point): array => [
                'date' => $point['date'],
                'balance' => $point['balance'],
                'below_minimum' => $point['belowMinimum'],
                'flows' => $point['flows'],
            ], $this->points),
        ];
    }
}
