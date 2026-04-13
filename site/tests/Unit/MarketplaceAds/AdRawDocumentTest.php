<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use PHPUnit\Framework\TestCase;

final class AdRawDocumentTest extends TestCase
{
    public function testMarkAsProcessedFromDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->build();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());

        $updatedAtBefore = $doc->getUpdatedAt();
        // Даём секунде проскочить, чтобы сравнение updatedAt не было идентичным.
        usleep(1000);

        $doc->markAsProcessed();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());
        self::assertGreaterThanOrEqual($updatedAtBefore, $doc->getUpdatedAt());
    }

    public function testMarkAsProcessedFromProcessedThrows(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже обработан');

        $doc->markAsProcessed();
    }

    public function testResetToDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $doc->resetToDraft();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
    }

    public function testResetToDraftFromDraftThrows(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->build();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже в статусе черновик');

        $doc->resetToDraft();
    }

    public function testUpdatePayloadResetsToDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $newPayload = '{"rows":[{"sku":"NEW","spend":42}]}';
        $doc->updatePayload($newPayload);

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
        self::assertSame($newPayload, $doc->getRawPayload());
    }
}
