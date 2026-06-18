<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class MappedControlSum
{
    public function __construct(
        public string $operationGroupId,
        public string $currency,
        public int $amountMinor,
    ) {
        Assert::uuid($this->operationGroupId);
        Assert::regex($this->currency, '/^[A-Z]{3}$/');
        Assert::greaterThanEq($this->amountMinor, 0);
    }
}
