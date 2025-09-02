<?php

namespace App\DTO;

use App\Enum\CashDirection;
use Symfony\Component\Validator\Constraints as Assert;

class CashTransactionDTO
{
    #[Assert\NotNull]
    public string $companyId;

    #[Assert\NotNull]
    public string $moneyAccountId;

    public ?string $counterpartyId = null;
    public ?string $cashflowCategoryId = null;

    #[Assert\Choice(callback: [CashDirection::class, 'cases'])]
    public CashDirection $direction;

    #[Assert\Positive]
    public string $amount;

    #[Assert\Length(3)]
    public string $currency;

    #[Assert\NotNull]
    public \DateTimeImmutable $occurredAt;

    public ?\DateTimeImmutable $bookedAt = null;

    public ?string $description = null;
    public ?string $externalId = null;

    public function __construct()
    {
    }
}
