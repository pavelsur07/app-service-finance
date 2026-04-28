<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Inventory\Enum\StockStatus;
use App\Inventory\Repository\StockSnapshotRepository;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: StockSnapshotRepository::class)]
#[ORM\Table(name: 'inventory_stock_snapshots')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_stock_snapshot_day_item', columns: ['company_id', 'snapshot_date', 'listing_id', 'product_id', 'location_id', 'status'])]
#[ORM\Index(columns: ['company_id', 'snapshot_date'], name: 'idx_inventory_stock_company_date')]
#[ORM\Index(columns: ['company_id', 'product_id', 'snapshot_date'], name: 'idx_inventory_stock_company_product_date')]
#[ORM\Index(columns: ['company_id', 'listing_id', 'snapshot_date'], name: 'idx_inventory_stock_company_listing_date')]
#[ORM\Index(columns: ['company_id', 'location_id', 'snapshot_date'], name: 'idx_inventory_stock_company_location_date')]
#[ORM\Index(columns: ['snapshot_session_id'], name: 'idx_inventory_stock_session')]
class StockSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $snapshotSessionId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $snapshotDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $snapshotAt;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $listingId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $productId;

    #[ORM\Column(type: Types::GUID)]
    private string $locationId;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: StockStatus::class)]
    private StockStatus $status;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $source;

    /**
     * Указывает на InventoryRawSnapshot, из которого получены ТЕКУЩИЕ значения
     * quantity и snapshot_at этой записи.
     *
     * При UPSERT-апдейте этой записи (повторная загрузка за тот же день) поле
     * обновляется вместе с quantity, snapshot_at и snapshot_session_id.
     *
     * Для трассировки полной истории загрузок данной позиции (всех raw,
     * которые её затрагивали) — запрашивать InventoryRawSnapshot с фильтром
     * по company_id + listing_id + product_id + location_id + status,
     * ORDER BY created_at.
     */
    #[ORM\Column(type: Types::GUID)]
    private string $rawSnapshotId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $companyId,
        string $snapshotSessionId,
        \DateTimeImmutable $snapshotDate,
        \DateTimeImmutable $snapshotAt,
        string $locationId,
        StockStatus $status,
        string $quantity,
        MarketplaceType $source,
        string $rawSnapshotId,
        ?string $listingId = null,
        ?string $productId = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($snapshotSessionId);
        Assert::uuid($locationId);
        Assert::uuid($rawSnapshotId);
        Assert::numeric($quantity);
        Assert::true(
            bccomp($quantity, '0', 3) >= 0,
            sprintf('Quantity must be non-negative, got: %s', $quantity),
        );

        if ($listingId !== null) {
            Assert::uuid($listingId);
        }

        if ($productId !== null) {
            Assert::uuid($productId);
        }

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->snapshotSessionId = $snapshotSessionId;
        $this->snapshotDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $snapshotDate->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
        $this->snapshotAt = $snapshotAt;
        $this->listingId = $listingId;
        $this->productId = $productId;
        $this->locationId = $locationId;
        $this->status = $status;
        $this->quantity = $quantity;
        $this->source = $source;
        $this->rawSnapshotId = $rawSnapshotId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSnapshotSessionId(): string
    {
        return $this->snapshotSessionId;
    }

    public function getSnapshotDate(): \DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function getSnapshotAt(): \DateTimeImmutable
    {
        return $this->snapshotAt;
    }

    public function getListingId(): ?string
    {
        return $this->listingId;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getLocationId(): string
    {
        return $this->locationId;
    }

    public function getStatus(): StockStatus
    {
        return $this->status;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getSource(): MarketplaceType
    {
        return $this->source;
    }

    public function getRawSnapshotId(): string
    {
        return $this->rawSnapshotId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
