<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OrderStatus;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_orders')]
#[ORM\UniqueConstraint(
    name: 'uniq_mp_order',
    columns: ['company_id', 'marketplace', 'external_order_id'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_mp_order_company')]
#[ORM\Index(columns: ['company_id', 'order_date'], name: 'idx_mp_order_company_date')]
#[ORM\Index(columns: ['listing_id', 'order_date'], name: 'idx_mp_order_listing_date')]
#[ORM\Index(columns: ['company_id', 'status'], name: 'idx_mp_order_company_status')]
class MarketplaceOrder
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $listingId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'string', length: 100)]
    private string $externalOrderId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $orderDate;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'string', enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $rawDocumentId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $listingId,
        MarketplaceType $marketplace,
        string $externalOrderId,
        \DateTimeImmutable $orderDate,
        int $quantity,
        OrderStatus $status = OrderStatus::ORDERED,
        ?string $rawDocumentId = null,
        ?array $rawData = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($listingId);
        Assert::notEmpty($externalOrderId);
        Assert::maxLength($externalOrderId, 100);
        Assert::greaterThan($quantity, 0);

        $this->companyId       = $companyId;
        $this->listingId       = $listingId;
        $this->marketplace     = $marketplace;
        $this->externalOrderId = $externalOrderId;
        $this->orderDate       = $orderDate;
        $this->quantity        = $quantity;
        $this->status          = $status;
        $this->rawDocumentId   = $rawDocumentId;
        $this->rawData         = $rawData;
        $this->createdAt       = new \DateTimeImmutable();
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function changeStatus(OrderStatus $newStatus): void
    {
        $this->status    = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateRawData(?string $rawDocumentId, ?array $rawData): void
    {
        $this->rawDocumentId = $rawDocumentId;
        $this->rawData       = $rawData;
        $this->updatedAt     = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getListingId(): string { return $this->listingId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getExternalOrderId(): string { return $this->externalOrderId; }
    public function getOrderDate(): \DateTimeImmutable { return $this->orderDate; }
    public function getQuantity(): int { return $this->quantity; }
    public function getStatus(): OrderStatus { return $this->status; }
    public function getRawDocumentId(): ?string { return $this->rawDocumentId; }
    public function getRawData(): ?array { return $this->rawData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
