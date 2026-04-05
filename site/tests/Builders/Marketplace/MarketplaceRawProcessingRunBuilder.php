<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceRawProcessingRun;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineTrigger;

final class MarketplaceRawProcessingRunBuilder
{
    public const DEFAULT_COMPANY_ID    = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_RAW_DOC_ID    = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_DOCUMENT_TYPE = 'sales_report';

    private string $companyId    = self::DEFAULT_COMPANY_ID;
    private string $rawDocumentId = self::DEFAULT_RAW_DOC_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $documentType = self::DEFAULT_DOCUMENT_TYPE;
    private PipelineTrigger $trigger = PipelineTrigger::AUTO;
    private string $profileCode = self::DEFAULT_DOCUMENT_TYPE;

    private function __construct() {}

    public static function aRun(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }

    public function withRawDocumentId(string $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;
        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;
        return $clone;
    }

    public function withDocumentType(string $documentType): self
    {
        $clone = clone $this;
        $clone->documentType = $documentType;
        $clone->profileCode  = $documentType;
        return $clone;
    }

    public function withTrigger(PipelineTrigger $trigger): self
    {
        $clone = clone $this;
        $clone->trigger = $trigger;
        return $clone;
    }

    public function build(): MarketplaceRawProcessingRun
    {
        return new MarketplaceRawProcessingRun(
            $this->companyId,
            $this->rawDocumentId,
            $this->marketplace,
            $this->documentType,
            $this->trigger,
            $this->profileCode,
        );
    }
}
