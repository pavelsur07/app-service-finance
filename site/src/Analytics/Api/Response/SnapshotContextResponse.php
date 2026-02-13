<?php

namespace App\Analytics\Api\Response;

use DateTimeImmutable;

final readonly class SnapshotContextResponse
{
    public function __construct(
        private string $companyId,
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
        private int $days,
        private DateTimeImmutable $prevFrom,
        private DateTimeImmutable $prevTo,
        private ?string $vatMode,
        private DateTimeImmutable $lastUpdatedAt,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'from' => $this->from->format(DATE_ATOM),
            'to' => $this->to->format(DATE_ATOM),
            'days' => $this->days,
            'prev_from' => $this->prevFrom->format(DATE_ATOM),
            'prev_to' => $this->prevTo->format(DATE_ATOM),
            'vat_mode' => $this->vatMode,
            'last_updated_at' => $this->lastUpdatedAt->format(DATE_ATOM),
        ];
    }
}
