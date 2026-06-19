<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Event;

use Webmozart\Assert\Assert;

final readonly class NormalizationCompletedEvent
{
    /**
     * @param list<AffectedPeriod> $affectedPeriods
     */
    public function __construct(
        public string $companyId,
        public string $rawRecordId,
        public array $affectedPeriods,
    ) {
        Assert::uuid($this->companyId);
        Assert::uuid($this->rawRecordId);
        Assert::allIsInstanceOf($this->affectedPeriods, AffectedPeriod::class);
    }
}
