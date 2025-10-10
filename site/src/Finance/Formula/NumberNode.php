<?php
declare(strict_types=1);

namespace App\Finance\Formula;

final class NumberNode extends Node
{
    public function __construct(public readonly float $value) {}
}
