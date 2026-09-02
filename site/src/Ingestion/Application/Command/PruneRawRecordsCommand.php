<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class PruneRawRecordsCommand
{
    public function __construct(
        public int $olderThanDays,
        public int $limit,
        public bool $execute,
    ) {
        Assert::greaterThan($olderThanDays, 0);
        Assert::greaterThan($limit, 0);
    }
}
