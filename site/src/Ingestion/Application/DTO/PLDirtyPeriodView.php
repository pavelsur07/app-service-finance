<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Entity\PLDirtyPeriod;

final readonly class PLDirtyPeriodView
{
    public function __construct(
        public string $id,
        public string $companyId,
        public int $year,
        public int $month,
        public string $shopRef,
        public string $status,
        public string $reason,
        public int $attempts,
        public ?string $lastError,
        public string $markedAt,
        public ?string $rebuiltAt,
    ) {
    }

    public static function fromEntity(PLDirtyPeriod $period): self
    {
        return new self(
            id: $period->getId(),
            companyId: $period->getCompanyId(),
            year: $period->getPeriodYear(),
            month: $period->getPeriodMonth(),
            shopRef: $period->getShopRef(),
            status: $period->getStatus()->value,
            reason: $period->getReason()->value,
            attempts: $period->getAttempts(),
            lastError: $period->getLastError(),
            markedAt: $period->getMarkedAt()->format(\DateTimeInterface::ATOM),
            rebuiltAt: $period->getRebuiltAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}
