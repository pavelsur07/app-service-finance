<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class EnsureOzonAccrualCursorCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
    }
}
