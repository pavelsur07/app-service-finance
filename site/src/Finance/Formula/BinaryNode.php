<?php
declare(strict_types=1);

namespace App\Finance\Formula;

final class BinaryNode extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right
    ) {}
}
