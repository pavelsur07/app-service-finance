<?php

namespace App\Deals\Entity;

use App\Company\Entity\Company;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealStatus;
use App\Deals\Enum\DealType;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'deals')]
#[ORM\UniqueConstraint(name: 'uniq_deal_company_number', columns: ['company_id', 'number'])]
#[ORM\Index(name: 'idx_deal_company_recognized_at', columns: ['company_id', 'recognized_at'])]
#[ORM\Index(name: 'idx_deal_company_status', columns: ['company_id', 'status'])]
class Deal
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 32, enumType: DealType::class)]
    private DealType $type;

    #[ORM\Column(length: 32, enumType: DealChannel::class)]
    private DealChannel $channel;

    #[ORM\Column(length: 32, enumType: DealStatus::class, options: ['default' => DealStatus::DRAFT->value])]
    private DealStatus $status = DealStatus::DRAFT;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $recognizedAt;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, options: ['default' => '0'])]
    private string $totalAmount = '0';

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, options: ['default' => '0'])]
    private string $totalDirectCost = '0';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        string $number,
        DealType $type,
        DealChannel $channel,
        \DateTimeImmutable $recognizedAt,
    ) {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->number = $number;
        $this->type = $type;
        $this->channel = $channel;
        $this->recognizedAt = $recognizedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getType(): DealType
    {
        return $this->type;
    }

    public function setType(DealType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getChannel(): DealChannel
    {
        return $this->channel;
    }

    public function setChannel(DealChannel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getStatus(): DealStatus
    {
        return $this->status;
    }

    public function setStatus(DealStatus $status): self
    {
        $this->status = $status;

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

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency ? strtoupper($currency) : null;

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getTotalDirectCost(): string
    {
        return $this->totalDirectCost;
    }

    public function setTotalDirectCost(string $totalDirectCost): self
    {
        $this->totalDirectCost = $totalDirectCost;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isDraft(): bool
    {
        return $this->status === DealStatus::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DealStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === DealStatus::CANCELLED;
    }

    public function markConfirmed(): self
    {
        $this->status = DealStatus::CONFIRMED;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markCancelled(): self
    {
        $this->status = DealStatus::CANCELLED;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setHeaderFields(
        string $number,
        ?string $title,
        DealType $type,
        DealChannel $channel,
        DealStatus $status,
        \DateTimeImmutable $recognizedAt,
        ?\DateTimeImmutable $occurredAt,
        ?string $currency,
    ): self {
        $this->number = $number;
        $this->title = $title;
        $this->type = $type;
        $this->channel = $channel;
        $this->status = $status;
        $this->recognizedAt = $recognizedAt;
        $this->occurredAt = $occurredAt;
        $this->currency = $currency ? strtoupper($currency) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
