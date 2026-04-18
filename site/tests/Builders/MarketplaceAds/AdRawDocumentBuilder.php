<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;

final class AdRawDocumentBuilder
{
    public const DEFAULT_ID = '88888888-8888-8888-8888-888888888888';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_RAW_PAYLOAD = '{"rows":[]}';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private \DateTimeImmutable $reportDate;
    private string $rawPayload = self::DEFAULT_RAW_PAYLOAD;
    private bool $processed = false;
    private ?string $failureReason = null;

    private function __construct()
    {
        $this->reportDate = new \DateTimeImmutable('2025-01-15');
    }

    public static function aRawDocument(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('88888888-8888-8888-8888-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withReportDate(\DateTimeImmutable $reportDate): self
    {
        $clone = clone $this;
        $clone->reportDate = $reportDate;

        return $clone;
    }

    public function withRawPayload(string $rawPayload): self
    {
        $clone = clone $this;
        $clone->rawPayload = $rawPayload;

        return $clone;
    }

    public function asProcessed(): self
    {
        $clone = clone $this;
        $clone->processed = true;
        $clone->failureReason = null;

        return $clone;
    }

    public function asFailed(string $reason = 'test failure'): self
    {
        $clone = clone $this;
        $clone->processed = false;
        $clone->failureReason = $reason;

        return $clone;
    }

    public function build(): AdRawDocument
    {
        $doc = new AdRawDocument(
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            reportDate: $this->reportDate,
            rawPayload: $this->rawPayload,
        );

        // Конструктор генерирует UUID v7, но Builder обязан выдавать детерминированный ID
        // (DEFAULT_ID или полученный через withIndex()), иначе тесты не могут ссылаться на сущность по ID.
        $idProperty = new \ReflectionProperty(AdRawDocument::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($doc, $this->id);

        if ($this->processed) {
            $doc->markAsProcessed();
        } elseif (null !== $this->failureReason) {
            $doc->markFailed($this->failureReason);
        }

        return $doc;
    }
}
