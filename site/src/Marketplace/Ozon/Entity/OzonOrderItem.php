<?php

namespace App\Marketplace\Ozon\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ozon_order_items')]
#[ORM\UniqueConstraint(name: 'uniq_order_item', columns: ['order_id', 'sku', 'offer_id'])]
#[ORM\Index(name: 'idx_product', columns: ['product_id'])]
class OzonOrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: OzonOrder::class)]
    #[ORM\JoinColumn(nullable: false)]
    private OzonOrder $order;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $offerId = null;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $price = '0';

    #[ORM\ManyToOne(targetEntity: OzonProduct::class)]
    private ?OzonProduct $product = null;

    #[ORM\Column(type: 'json')]
    private array $raw = [];

    public function __construct(string $id, OzonOrder $order)
    {
        $this->id = $id;
        $this->order = $order;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOrder(): OzonOrder
    {
        return $this->order;
    }

    public function setOrder(OzonOrder $order): void
    {
        $this->order = $order;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): void
    {
        $this->sku = $sku;
    }

    public function getOfferId(): ?string
    {
        return $this->offerId;
    }

    public function setOfferId(?string $offerId): void
    {
        $this->offerId = $offerId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function getProduct(): ?OzonProduct
    {
        return $this->product;
    }

    public function setProduct(?OzonProduct $product): void
    {
        $this->product = $product;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): void
    {
        $this->raw = $raw;
    }
}
