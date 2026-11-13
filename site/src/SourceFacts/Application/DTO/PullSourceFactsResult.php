<?php

declare(strict_types=1);

namespace App\SourceFacts\Application\DTO;

final readonly class PullSourceFactsResult
{
    public function __construct(
        public int $received,
        public int $applied,
        public int $superseded,
        public int $quarantined,
        public int $skipped,
        public int $facts,
    ) {
    }
}
