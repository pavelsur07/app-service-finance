<?php

declare(strict_types=1);

namespace App\DTO;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Entity\Counterparty;
use Symfony\Component\Validator\Constraints as Assert;

final class PaymentPlanDTO
{
    #[Assert\NotNull]
    public \DateTimeInterface $plannedAt;

    #[Assert\NotEqualTo(0)]
    public string $amount;

    #[Assert\NotNull]
    public ?CashflowCategory $cashflowCategory = null;

    public ?MoneyAccount $moneyAccount = null;

    public ?Counterparty $counterparty = null;

    public ?string $comment = null;

    public ?string $status = null;

    public function __construct()
    {
        $this->plannedAt = new \DateTimeImmutable();
        $this->amount = '0';
    }
}
