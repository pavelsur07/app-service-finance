<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class PushRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $documentType,
        public array $payload,
        public string $idempotencyKey,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->documentType);
        Assert::notEmpty($this->idempotencyKey);
    }
}
