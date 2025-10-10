<?php
declare(strict_types=1);

namespace App\Finance\Formula;

final class UnaryNode extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $expr
    ) {}
}
