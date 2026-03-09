<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceBarcodeCatalogRepository::class)]
#[ORM\Table(name: 'marketplace_barcode_catalog')]
#[ORM\UniqueConstraint(name: 'uniq_company_marketplace_barcode', columns: ['company_id', 'marketplace', 'barcode'])]
#[ORM\Index(columns: ['company_id', 'marketplace', 'external_id'], name: 'idx_barcode_catalog_external')]
class MarketplaceBarcodeCatalog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    /** nm_id для WB, product_id для Ozon и т.д. */
    #[ORM\Column(length: 100)]
    private string $externalId;

    #[ORM\Column(length: 100)]
    private string $barcode;

    #[ORM\Column(length: 50)]
    private string $size;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceType $marketplace,
        string $externalId,
        string $barcode,
        string $size,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($externalId);
        Assert::notEmpty($barcode);
        Assert::notEmpty($size);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->externalId = $externalId;
        $this->barcode = $barcode;
        $this->size = $size;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getSize(): string
    {
        return $this->size;
    }
}
