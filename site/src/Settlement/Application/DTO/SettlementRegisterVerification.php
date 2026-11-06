<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class SettlementRegisterVerification
{
    /** @param list<string> $differences */
    public function __construct(public int $expectedMovements, public int $expectedLinks, public array $differences)
    {
    }
}
