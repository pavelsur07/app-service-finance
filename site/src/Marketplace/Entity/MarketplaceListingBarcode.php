<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceListingBarcodeRepository::class)]
#[ORM\Table(name: 'marketplace_listing_barcodes')]
#[ORM\UniqueConstraint(name: 'uniq_company_barcode', columns: ['company_id', 'barcode'])]
#[ORM\Index(columns: ['listing_id'], name: 'idx_listing_barcodes')]
class MarketplaceListingBarcode
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MarketplaceListing $listing;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 100)]
    private string $barcode;

    public function __construct(string $id, MarketplaceListing $listing, string $companyId, string $barcode)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($barcode);

        $this->id = $id;
        $this->listing = $listing;
        $this->companyId = $companyId;
        $this->barcode = $barcode;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getListing(): MarketplaceListing
    {
        return $this->listing;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }
}
