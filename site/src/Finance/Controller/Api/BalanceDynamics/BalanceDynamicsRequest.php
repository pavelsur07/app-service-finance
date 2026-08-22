<?php

declare(strict_types=1);

namespace App\Finance\Controller\Api\BalanceDynamics;

use App\Cash\Enum\FiatCurrency;
use Symfony\Component\HttpFoundation\Request;

final readonly class BalanceDynamicsRequest
{
    private const ALLOWED_PERIODS = [30, 60, 90];

    public function __construct(
        public int $periodDays,
        public FiatCurrency $currency,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $parameters = $request->query->all();
        $period = $parameters['period'] ?? '30';
        if (!is_string($period) || !ctype_digit($period)) {
            throw new \InvalidArgumentException('Period must be one of: 30, 60, 90.');
        }

        $periodDays = (int) $period;
        if (!in_array($periodDays, self::ALLOWED_PERIODS, true)) {
            throw new \InvalidArgumentException('Period must be one of: 30, 60, 90.');
        }

        $currency = $parameters['currency'] ?? FiatCurrency::RUB->value;
        if (!is_string($currency)) {
            throw new \InvalidArgumentException('Currency must be a supported fiat code.');
        }

        return new self($periodDays, FiatCurrency::fromCode($currency));
    }
}
