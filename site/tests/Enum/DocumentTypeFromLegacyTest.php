<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeFromLegacyTest extends TestCase
{
    public function testDeliveryNoteMapping(): void
    {
        self::assertSame(DocumentType::SALES, DocumentType::fromLegacy('Накладная'));
    }

    public function testMarketplaceReportMapping(): void
    {
        self::assertSame(DocumentType::SALES, DocumentType::fromLegacy('Отчет маркетплейса'));
    }

    public function testServiceActMapping(): void
    {
        self::assertSame(DocumentType::SALES, DocumentType::fromLegacy('SERVICE_ACT'));
    }

    public function testUnknownMappingFallsBackToOther(): void
    {
        self::assertSame(DocumentType::OTHER, DocumentType::fromLegacy('Неизвестная форма'));
    }
}
