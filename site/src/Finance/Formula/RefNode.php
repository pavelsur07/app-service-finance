<?php
declare(strict_types=1);

namespace App\Finance\Formula;

final class RefNode extends Node
{
    public function __construct(public readonly string $code) {}
}
