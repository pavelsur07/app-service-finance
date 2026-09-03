<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class RefreshOrderStatusesCommand
{
    public function __construct(
        public int $days,
        public int $limitPerConnection,
        public ?string $companyId = null,
    ) {
        Assert::greaterThan($days, 0);
        Assert::greaterThan($limitPerConnection, 0);
        if (null !== $companyId) {
            Assert::uuid($companyId);
        }
    }
}
