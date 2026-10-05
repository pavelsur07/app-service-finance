<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

final readonly class FailedQueueAssessment
{
    /**
     * @param list<string> $reasons причины вердикта человеческим языком
     */
    public function __construct(
        public FailedQueueVerdict $verdict,
        public array $reasons,
    ) {
    }
}
