<?php

declare(strict_types=1);

namespace App\DTO;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanSource;
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

    public ?\DateTimeInterface $expectedAt = null;

    #[Assert\Range(min: 0, max: 100)]
    public int $probability = 100;

    public bool $isFrozen = false;

    public PaymentPlanSource $source = PaymentPlanSource::MANUAL;

    public ?string $externalId = null;

    public function __construct()
    {
        $this->plannedAt = new \DateTimeImmutable();
        $this->expectedAt = $this->plannedAt;
        $this->amount = '0';
    }
}
