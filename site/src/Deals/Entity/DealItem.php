<?php

namespace App\Deals\Entity;

use App\Deals\Enum\DealItemKind;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'deal_items')]
#[ORM\Index(name: 'idx_deal_item_deal', columns: ['deal_id'])]
#[ORM\UniqueConstraint(name: 'uniq_deal_item_deal_line_index', columns: ['deal_id', 'line_index'])]
class DealItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Deal::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'deal_id', nullable: false, onDelete: 'CASCADE')]
    private ?Deal $deal = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(enumType: DealItemKind::class)]
    private DealItemKind $kind;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $qty;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'integer')]
    private int $lineIndex;

    public function __construct(
        string $name,
        DealItemKind $kind,
        string $qty,
        string $price,
        string $amount,
        int $lineIndex,
        ?Deal $deal = null,
        ?string $id = null,
        ?string $unit = null,
    ) {
        $id = $id ?? Uuid::uuid4()->toString();
        Assert::uuid($id);
        $this->id = $id;
        $this->name = $name;
        $this->kind = $kind;
        $this->qty = $qty;
        $this->price = $price;
        $this->amount = $amount;
        $this->lineIndex = $lineIndex;
        $this->deal = $deal;
        $this->unit = $unit;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): DealItemKind
    {
        return $this->kind;
    }

    public function setKind(DealItemKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getQty(): string
    {
        return $this->qty;
    }

    public function setQty(string $qty): self
    {
        $this->qty = $qty;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

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

    public function getLineIndex(): int
    {
        return $this->lineIndex;
    }

    public function setLineIndex(int $lineIndex): self
    {
        $this->lineIndex = $lineIndex;

        return $this;
    }
}
