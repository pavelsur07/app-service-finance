<?php

declare(strict_types=1);

namespace App\Cash\DTO;

use App\Cash\Entity\Accounts\MoneyAccount;
use Symfony\Component\Validator\Constraints as Assert;

final class CashTransferFormData
{
    #[Assert\NotNull]
    public ?MoneyAccount $sourceAccount = null;

    #[Assert\NotNull]
    public ?MoneyAccount $targetAccount = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(?:[.,]\d{1,2})?$/', message: 'Укажите положительную сумму с точностью не более двух знаков.')]
    public ?string $sourceAmount = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(?:[.,]\d{1,2})?$/', message: 'Укажите положительную сумму с точностью не более двух знаков.')]
    public ?string $targetAmount = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $occurredAt;

    #[Assert\Length(max: 1024)]
    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public string $idempotencyKey;

    public function __construct(string $idempotencyKey)
    {
        $this->occurredAt = new \DateTimeImmutable('today');
        $this->idempotencyKey = $idempotencyKey;
    }

    public function normalizedSourceAmount(): string
    {
        return str_replace(',', '.', trim((string) $this->sourceAmount));
    }

    public function normalizedTargetAmount(): string
    {
        return str_replace(',', '.', trim((string) $this->targetAmount));
    }

    public function requiredOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt ?? throw new \LogicException('Transfer date is required after form validation.');
    }
}
