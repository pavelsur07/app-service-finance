<?php

declare(strict_types=1);

namespace App\Ai\Dto;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

final class QaRequestContext
{
    public function __construct(
        public readonly UuidInterface $companyId,
        public readonly string $question,
        public readonly ?DateTimeImmutable $periodFrom = null,
        public readonly ?DateTimeImmutable $periodTo = null,
    ) {
    }
}
