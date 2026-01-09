<?php

declare(strict_types=1);

namespace App\Finance\Formula;

final class Functions
{
    public static function sum(float ...$args): float
    {
        return array_sum($args);
    }

    public static function safeDiv(float $a, float $b): float
    {
        return (0.0 == $b) ? 0.0 : $a / $b;
    }

    public static function ifCond(bool $cond, float $a, float $b): float
    {
        return $cond ? $a : $b;
    }
}
