<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

/**
 * Сводка одного прохода poll-cron'а по компании (или суммарно по нескольким).
 *
 * @see \App\MarketplaceAds\Application\Service\OzonAdReportPoller
 * @see \App\MarketplaceAds\Command\OzonPollReportsCommand
 */
final readonly class PollResult
{
    public function __construct(
        public int $seen,
        public int $updated,
        public int $finalized,
        public int $errors,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            seen: $this->seen + $other->seen,
            updated: $this->updated + $other->updated,
            finalized: $this->finalized + $other->finalized,
            errors: $this->errors + $other->errors,
        );
    }
}
