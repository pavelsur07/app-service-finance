<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladStockSnapshotLineRepository::class)]
#[ORM\Table(name: 'moysklad_stock_snapshot_lines')]
#[ORM\Index(columns: ['snapshot_id'], name: 'idx_moysklad_stock_lines_snapshot')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'store_external_id'], name: 'idx_moysklad_stock_lines_store')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'product_external_id'], name: 'idx_moysklad_stock_lines_product')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'variant_external_id'], name: 'idx_moysklad_stock_lines_variant')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_stock_lines_product', columns: ['snapshot_id', 'store_external_id', 'product_external_id'], options: ['where' => 'product_external_id IS NOT NULL'])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_stock_lines_variant', columns: ['snapshot_id', 'store_external_id', 'variant_external_id'], options: ['where' => 'variant_external_id IS NOT NULL'])]
class MoySkladStockSnapshotLine
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;

    #[ORM\Column(type: Types::GUID)]
    private string $snapshotId;

    #[ORM\Column(type: Types::GUID)]
    private string $storeExternalId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $productExternalId = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $variantExternalId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 10)]
    private string $stock;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 10)]
    private string $reserve;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 10)]
    private string $inTransit;

    public function __construct(
        string $id,
        string $companyId,
        string $connectionId,
        string $snapshotId,
        string $storeExternalId,
        string $assortmentType,
        string $assortmentExternalId,
        string $stock,
        string $reserve,
        string $inTransit,
    ) {
        foreach ([$id, $companyId, $connectionId, $snapshotId, $storeExternalId, $assortmentExternalId] as $uuid) {
            Assert::uuid($uuid);
        }
        if (!in_array($assortmentType, ['product', 'variant'], true)) {
            throw new \InvalidArgumentException('Invalid stock assortment type.');
        }
        foreach ([$stock, $reserve, $inTransit] as $decimal) {
            Assert::regex($decimal, '/^-?(?:0|[1-9]\d{0,19})(?:\.\d{1,10})?$/D');
        }

        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->snapshotId = strtolower($snapshotId);
        $this->storeExternalId = strtolower($storeExternalId);
        if ('product' === $assortmentType) {
            $this->productExternalId = strtolower($assortmentExternalId);
        } else {
            $this->variantExternalId = strtolower($assortmentExternalId);
        }
        $this->stock = $stock;
        $this->reserve = $reserve;
        $this->inTransit = $inTransit;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSnapshotId(): string
    {
        return $this->snapshotId;
    }

    public function getProductExternalId(): ?string
    {
        return $this->productExternalId;
    }

    public function getVariantExternalId(): ?string
    {
        return $this->variantExternalId;
    }

    public function getStock(): string
    {
        return $this->stock;
    }
}
