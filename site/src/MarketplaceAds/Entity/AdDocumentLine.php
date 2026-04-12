<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\MarketplaceAds\Repository\AdDocumentLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: AdDocumentLineRepository::class)]
#[ORM\Table(name: 'marketplace_ad_document_lines')]
#[ORM\Index(columns: ['ad_document_id'], name: 'idx_ad_document_line_document')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_ad_document_line_listing')]
class AdDocumentLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AdDocument::class)]
    #[ORM\JoinColumn(name: 'ad_document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AdDocument $adDocument;

    #[ORM\Column(type: 'guid')]
    private string $listingId;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 4)]
    private string $sharePercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $cost;

    #[ORM\Column(type: 'integer')]
    private int $impressions;

    #[ORM\Column(type: 'integer')]
    private int $clicks;

    public function __construct(
        AdDocument $adDocument,
        string $listingId,
        string $sharePercent,
        string $cost,
        int $impressions,
        int $clicks,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($listingId);

        $this->adDocument   = $adDocument;
        $this->listingId    = $listingId;
        $this->sharePercent = $sharePercent;
        $this->cost         = $cost;
        $this->impressions  = $impressions;
        $this->clicks       = $clicks;
    }

    public function getId(): string { return $this->id; }
    public function getAdDocument(): AdDocument { return $this->adDocument; }
    public function getAdDocumentId(): string { return $this->adDocument->getId(); }
    public function getListingId(): string { return $this->listingId; }
    public function getSharePercent(): string { return $this->sharePercent; }
    public function getCost(): string { return $this->cost; }
    public function getImpressions(): int { return $this->impressions; }
    public function getClicks(): int { return $this->clicks; }
}
