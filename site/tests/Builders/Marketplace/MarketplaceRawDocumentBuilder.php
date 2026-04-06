<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use Ramsey\Uuid\Uuid;

final class MarketplaceRawDocumentBuilder
{
    private string $id;
    private ?Company $company = null;
    private MarketplaceType $marketplace = MarketplaceType::OZON;
    private string $documentType = 'sales_report';
    private \DateTimeImmutable $periodFrom;
    private \DateTimeImmutable $periodTo;
    private array $rawData = [];
    private string $apiEndpoint = '/test/endpoint';

    private function __construct()
    {
        $this->id         = Uuid::uuid4()->toString();
        $this->periodFrom = new \DateTimeImmutable('2026-01-01');
        $this->periodTo   = new \DateTimeImmutable('2026-01-31');
    }

    public static function aDocument(): self
    {
        return new self();
    }

    public function forCompany(Company $company): self
    {
        $clone          = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone              = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withDocumentType(string $documentType): self
    {
        $clone               = clone $this;
        $clone->documentType = $documentType;

        return $clone;
    }

    public function withPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $clone             = clone $this;
        $clone->periodFrom = $from;
        $clone->periodTo   = $to;

        return $clone;
    }

    public function build(): MarketplaceRawDocument
    {
        if ($this->company === null) {
            throw new \LogicException('Company is required. Call forCompany().');
        }

        $doc = new MarketplaceRawDocument(
            $this->id,
            $this->company,
            $this->marketplace,
            $this->documentType,
        );

        $doc->setPeriodFrom($this->periodFrom);
        $doc->setPeriodTo($this->periodTo);
        $doc->setRawData($this->rawData);
        $doc->setApiEndpoint($this->apiEndpoint);

        return $doc;
    }
}
