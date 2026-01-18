<?php

namespace App\Cash\Entity\PaymentPlan;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PaymentPlanMatchRepository::class)]
#[ORM\Table(name: 'payment_plan_match')]
#[ORM\UniqueConstraint(name: 'uniq_payment_plan_match_transaction', columns: ['transaction_id'])]
#[ORM\Index(name: 'idx_payment_plan_match_company_plan', columns: ['company_id', 'plan_id'])]
#[ORM\Index(name: 'idx_payment_plan_match_company_transaction', columns: ['company_id', 'transaction_id'])]
class PaymentPlanMatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: PaymentPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PaymentPlan $plan;

    #[ORM\ManyToOne(targetEntity: CashTransaction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CashTransaction $transaction;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $matchedAt;

    public function __construct(string $id, Company $company, PaymentPlan $plan, CashTransaction $transaction, \DateTimeImmutable $matchedAt)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->plan = $plan;
        $this->transaction = $transaction;
        $this->matchedAt = $matchedAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPlan(): PaymentPlan
    {
        return $this->plan;
    }

    public function getTransaction(): CashTransaction
    {
        return $this->transaction;
    }

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }
}
