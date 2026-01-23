<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service\CommissionerReport\Dto;

final readonly class ValidationResultDTO
{
    /**
     * @param list<string|null> $headersNormalized
     * @param list<string> $requiredMissing
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    public function __construct(
        public array $headersNormalized,
        public string $headersHash,
        public array $requiredMissing,
        public array $warnings,
        public array $errors,
        public string $status,
    ) {
    }
}
