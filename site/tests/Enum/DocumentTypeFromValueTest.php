<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeFromValueTest extends TestCase
{
    public function testFromValueReturnsEnumCaseForKnownValue(): void
    {
        self::assertSame(DocumentType::SALES, DocumentType::fromValue('SALES'));
    }

    public function testFromValueIsCaseInsensitive(): void
    {
        self::assertSame(DocumentType::PAYROLL, DocumentType::fromValue('payroll'));
    }

    public function testFromValueSupportsLegacyServiceActAlias(): void
    {
        self::assertSame(DocumentType::SALES, DocumentType::fromValue('SERVICE_ACT'));
    }

    public function testFromValueThrowsForUnknownValue(): void
    {
        $this->expectException(\ValueError::class);
        DocumentType::fromValue('UNKNOWN_TYPE');
    }
}
