<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeFromLegacyTest extends TestCase
{
    public function testDeliveryNoteMapping(): void
    {
        self::assertSame(DocumentType::SALES_DELIVERY_NOTE, DocumentType::fromLegacy('Накладная'));
    }

    public function testMarketplaceReportMapping(): void
    {
        self::assertSame(DocumentType::COMMISSION_REPORT, DocumentType::fromLegacy('Отчет маркетплейса'));
    }

    public function testUnknownMappingFallsBackToOther(): void
    {
        self::assertSame(DocumentType::OTHER, DocumentType::fromLegacy('Неизвестная форма'));
    }
}
