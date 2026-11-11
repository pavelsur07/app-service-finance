<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanSource;
use App\Company\Entity\Counterparty;
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

    /** Ключ повтора формы создания = ID плана (UUIDv7 из скрытого поля). */
    public ?string $idempotencyKey = null;

    public static function fromPlan(\App\Cash\Entity\PaymentPlan\PaymentPlan $plan): self
    {
        $dto = new self();
        $dto->plannedAt = $plan->getPlannedAt() ?? throw new \LogicException('Плановый платёж не имеет даты.');
        $dto->amount = $plan->getAmount();
        $dto->cashflowCategory = $plan->getCashflowCategory();
        $dto->moneyAccount = $plan->getMoneyAccount();
        $dto->counterparty = $plan->getCounterparty();
        $dto->comment = $plan->getComment();
        $dto->status = $plan->getStatus()->value;
        $dto->probability = $plan->getProbability();
        $dto->isFrozen = $plan->isFrozen();
        $dto->expectedAt = $plan->getExpectedAt();

        return $dto;
    }

    public function __construct()
    {
        $this->plannedAt = new \DateTimeImmutable();
        $this->expectedAt = $this->plannedAt;
        $this->amount = '0';
    }
}
