<?php

namespace App\Deals\Entity;

use App\Deals\Enum\DealAdjustmentType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'deal_adjustments')]
#[ORM\Index(name: 'idx_deal_adjustment_deal', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_deal_adjustment_recognized_at', columns: ['recognized_at'])]
class DealAdjustment
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Deal::class, inversedBy: 'adjustments')]
    #[ORM\JoinColumn(name: 'deal_id', nullable: false, onDelete: 'CASCADE')]
    private ?Deal $deal = null;

    #[ORM\Column(length: 32, enumType: DealAdjustmentType::class)]
    private DealAdjustmentType $type;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $recognizedAt;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    public function __construct(
        \DateTimeImmutable $recognizedAt,
        string $amount,
        DealAdjustmentType $type,
        ?Deal $deal = null,
        ?string $comment = null,
        ?string $id = null,
    ) {
        $id = $id ?? Uuid::uuid4()->toString();
        Assert::uuid($id);
        $this->id = $id;
        $this->recognizedAt = $recognizedAt;
        $this->amount = $amount;
        $this->type = $type;
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

    public function getType(): DealAdjustmentType
    {
        return $this->type;
    }

    public function setType(DealAdjustmentType $type): self
    {
        $this->type = $type;

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
