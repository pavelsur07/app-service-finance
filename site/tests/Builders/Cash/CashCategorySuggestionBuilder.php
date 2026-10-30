<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashCategorySuggestion;

final class CashCategorySuggestionBuilder
{
    public const DEFAULT_ID = '0192f0a0-0000-7000-8000-00000000a501';
    public const DEFAULT_COMPANY_ID = '0192f0a0-0000-7000-8000-0000000000c1';
    public const DEFAULT_TRANSACTION_ID = '0192f0a0-0000-7000-8000-0000000000e1';
    public const DEFAULT_TASK_ID = '0192f0a0-0000-5000-8000-0000000000a1';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $transactionId = self::DEFAULT_TRANSACTION_ID;
    private string $taskId = self::DEFAULT_TASK_ID;
    private \DateTimeImmutable $requestedAt;
    /** @var array{0: ?string, 1: float}|null */
    private ?array $suggested = null;

    private function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable('2026-10-30 12:00:00');
    }

    public static function aSuggestion(): self
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

    public function withTransactionId(string $transactionId): self
    {
        $clone = clone $this;
        $clone->transactionId = $transactionId;

        return $clone;
    }

    public function withTaskId(string $taskId): self
    {
        $clone = clone $this;
        $clone->taskId = $taskId;

        return $clone;
    }

    public function requestedAt(\DateTimeImmutable $at): self
    {
        $clone = clone $this;
        $clone->requestedAt = $at;

        return $clone;
    }

    /** Подсказка с ответом модели (статус suggested или no_answer). */
    public function suggesting(?string $categoryId, float $confidence = 0.9): self
    {
        $clone = clone $this;
        $clone->suggested = [$categoryId, $confidence];

        return $clone;
    }

    public function build(): CashCategorySuggestion
    {
        $suggestion = new CashCategorySuggestion($this->companyId, $this->transactionId, $this->taskId, $this->requestedAt, $this->id);
        if (null !== $this->suggested) {
            $suggestion->suggest($this->suggested[0], $this->suggested[1], 'accepted', 'stub/categorize', 'categorize_transactions/v1', $this->requestedAt->modify('+1 minute'));
        }

        return $suggestion;
    }
}
