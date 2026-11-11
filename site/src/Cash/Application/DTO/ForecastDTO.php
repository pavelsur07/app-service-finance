<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final class ForecastDTO
{
    public float $openingBalance = 0.0;
    public float $closingForecast = 0.0;
    public bool $hasGap = false;
    /** @var array<string,float> */
    public array $series = [];
}
