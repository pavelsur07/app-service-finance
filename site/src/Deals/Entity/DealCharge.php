<?php

namespace App\Deals\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'deal_charges')]
#[ORM\Index(name: 'idx_deal_charge_deal', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_deal_charge_charge_type', columns: ['charge_type_id'])]
class DealCharge
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Deal::class, inversedBy: 'charges')]
    #[ORM\JoinColumn(name: 'deal_id', nullable: false, onDelete: 'CASCADE')]
    private ?Deal $deal = null;

    #[ORM\ManyToOne(targetEntity: ChargeType::class)]
    #[ORM\JoinColumn(name: 'charge_type_id', nullable: false, onDelete: 'RESTRICT')]
    private ?ChargeType $chargeType = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $recognizedAt;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    public function __construct(
        \DateTimeImmutable $recognizedAt,
        string $amount,
        ChargeType $chargeType,
        ?Deal $deal = null,
        ?string $comment = null,
        ?string $id = null,
    ) {
        $id = $id ?? Uuid::uuid4()->toString();
        Assert::uuid($id);
        $this->id = $id;
        $this->recognizedAt = $recognizedAt;
        $this->amount = $amount;
        $this->chargeType = $chargeType;
        $this->deal = $deal;
        $this->comment = $comment;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getDeal(): ?Deal
    {
        return $this->deal;
    }

    public function setDeal(?Deal $deal): self
    {
        $this->deal = $deal;

        return $this;
    }

    public function getChargeType(): ?ChargeType
    {
        return $this->chargeType;
    }

    public function setChargeType(ChargeType $chargeType): self
    {
        $this->chargeType = $chargeType;

        return $this;
    }

    public function getRecognizedAt(): \DateTimeImmutable
    {
        return $this->recognizedAt;
    }

    public function setRecognizedAt(\DateTimeImmutable $recognizedAt): self
    {
        $this->recognizedAt = $recognizedAt;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }
}
