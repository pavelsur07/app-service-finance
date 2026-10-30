<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashCategorizationTask;

final class CashCategorizationTaskBuilder
{
    public const DEFAULT_ID = CashCategorySuggestionBuilder::DEFAULT_TASK_ID;
    public const DEFAULT_COMPANY_ID = CashCategorySuggestionBuilder::DEFAULT_COMPANY_ID;

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $requestKey = 'cash-categorize:0192f0a0-0000-7000-8000-0000000000f1';
    /** @var array<string, mixed> */
    private array $payload = ['categories' => [], 'operations' => []];
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('2026-10-30 12:00:00');
    }

    public static function aTask(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withRequestKey(string $requestKey): self
    {
        $clone = clone $this;
        $clone->requestKey = $requestKey;

        return $clone;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        $clone = clone $this;
        $clone->payload = $payload;

        return $clone;
    }

    public function createdAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): CashCategorizationTask
    {
        return new CashCategorizationTask($this->id, $this->companyId, $this->requestKey, $this->payload, $this->createdAt);
    }
}
