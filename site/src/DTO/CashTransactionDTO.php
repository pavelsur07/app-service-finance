<?php

namespace App\DTO;

use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Enum\CounterpartyType;
use Symfony\Component\Validator\Constraints as Assert;

class CashTransactionDTO
{
    #[Assert\NotNull]
    public string $companyId;

    #[Assert\NotNull]
    public string $moneyAccountId;

    public ?string $counterpartyId = null;
    public ?string $cashflowCategoryId = null;
    public ?string $projectDirectionId = null;

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

    /**
     * Код источника импорта (для составного ключа идемпотентности).
     */
    public ?string $importSource = null;

    /**
     * Внешний идентификатор транзакции из источника (банк/файл и т.д.).
     */
    public ?string $externalId = null;

    public ?string $payerInn = null;
    public ?string $payeeInn = null;
    public ?string $counterpartyNameRaw = null;
    public ?string $payerAccount = null;
    public ?string $payeeAccount = null;
    public ?string $payerBic = null;
    public ?string $payeeBank = null;
    public ?CounterpartyType $counterpartyType = null;

    public function __construct()
    {
    }
}
