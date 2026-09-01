<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Repository\IngestOrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Позиция заказа — место, где живёт связь с листингом.
 *
 * Связь именно здесь, а не на заказе: отправление Ozon содержит `products[]`
 * с несколькими SKU, а выкуп считается по листингу. Связь на заказе не дала бы
 * посчитать показатель в многострочном заказе.
 *
 * `lineNo` — индекс позиции в исходном массиве, и он часть уникального ключа.
 * Ключ по `externalSku` не годится: один SKU может повториться на двух строках
 * одного отправления, и перенормализация того же raw создала бы дубли.
 */
#[ORM\Entity(repositoryClass: IngestOrderItemRepository::class)]
#[ORM\Table(name: 'ingest_order_items')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_order_item_line', columns: ['company_id', 'order_id', 'line_no'])]
#[ORM\Index(name: 'idx_ingest_order_item_company_listing', columns: ['company_id', 'listing_id'])]
#[ORM\Index(name: 'idx_ingest_order_item_order', columns: ['order_id'])]
class IngestOrderItem implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $orderId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $lineNo;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $externalSku = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $offerId = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    /** Цена в минорных единицах: копейки у обоих маркетплейсов. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $priceMinor = null;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $currency = null;

    /** Признак выкупа на позиции; Ozon отдаёт его в products[].is_marketplace_buyout. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $marketplaceBuyout = false;

    /**
     * Связь с листингом Marketplace хранится скаляром: сущности чужого модуля
     * через границу не ходят.
     */
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $listingId = null;

    /**
     * Ключ, по которому пытались резолвить. Сохраняется даже когда листинг не
     * найден: нерезолвленное должно быть видимой очередью на разбор, а не
     * молчаливой потерей.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $listingSku = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $orderId,
        int $lineNo,
        int $quantity,
        ?string $externalSku = null,
        ?string $offerId = null,
        ?string $barcode = null,
        ?string $name = null,
        ?string $priceMinor = null,
        ?string $currency = null,
        bool $marketplaceBuyout = false,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($orderId);
        Assert::greaterThanEq($lineNo, 0);
        Assert::greaterThanEq($quantity, 0);

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->orderId = $orderId;
        $this->lineNo = $lineNo;
        $this->quantity = $quantity;
        $this->externalSku = $externalSku;
        $this->offerId = $offerId;
        $this->barcode = $barcode;
        $this->name = $name;
        $this->priceMinor = $priceMinor;
        $this->currency = $currency;
        $this->marketplaceBuyout = $marketplaceBuyout;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function linkListing(?string $listingId, ?string $listingSku): void
    {
        if (null !== $listingId) {
            Assert::uuid($listingId);
        }

        $this->listingId = $listingId;
        $this->listingSku = $listingSku ?? $this->listingSku;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function refresh(
        int $quantity,
        ?string $name,
        ?string $priceMinor,
        ?string $currency,
        bool $marketplaceBuyout,
    ): void {
        $this->quantity = $quantity;
        $this->name = $name ?? $this->name;
        $this->priceMinor = $priceMinor ?? $this->priceMinor;
        $this->currency = $currency ?? $this->currency;
        $this->marketplaceBuyout = $marketplaceBuyout;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getLineNo(): int
    {
        return $this->lineNo;
    }

    public function getExternalSku(): ?string
    {
        return $this->externalSku;
    }

    public function getOfferId(): ?string
    {
        return $this->offerId;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getPriceMinor(): ?string
    {
        return $this->priceMinor;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function isMarketplaceBuyout(): bool
    {
        return $this->marketplaceBuyout;
    }

    public function getListingId(): ?string
    {
        return $this->listingId;
    }

    public function getListingSku(): ?string
    {
        return $this->listingSku;
    }
}
