<?php

declare(strict_types=1);

namespace App\Finance\Formula;

final class FuncCallNode extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
    ) {
    }
}
