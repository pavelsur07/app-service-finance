<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\Service\OzonReportExtensionDetector;
use PHPUnit\Framework\TestCase;

final class OzonReportExtensionDetectorTest extends TestCase
{
    public function testMagicZipBytesReturnZipEvenWithCsvContentType(): void
    {
        $zipBytes = "PK\x03\x04".str_repeat("\x00", 50);

        self::assertSame('zip', OzonReportExtensionDetector::detect($zipBytes, 'text/csv'));
        self::assertSame('zip', OzonReportExtensionDetector::detect($zipBytes, 'text/csv; charset=utf-8'));
        self::assertSame('zip', OzonReportExtensionDetector::detect($zipBytes, null));
    }

    public function testContentTypeZipReturnsZipWhenBodyTooShortForMagic(): void
    {
        self::assertSame('zip', OzonReportExtensionDetector::detect('abc', 'application/zip'));
        self::assertSame('zip', OzonReportExtensionDetector::detect('', 'application/x-zip-compressed'));
        self::assertSame('zip', OzonReportExtensionDetector::detect('abc', 'application/zip; charset=binary'));
    }

    public function testCsvWhenNoMagicAndNoZipContentType(): void
    {
        self::assertSame('csv', OzonReportExtensionDetector::detect('col1,col2\nv1,v2', 'text/csv'));
        self::assertSame('csv', OzonReportExtensionDetector::detect('col1,col2', null));
        self::assertSame('csv', OzonReportExtensionDetector::detect('col1,col2', 'text/plain'));
        self::assertSame('csv', OzonReportExtensionDetector::detect('', null));
    }

    public function testContentTypeParsingIsCaseInsensitiveAndIgnoresParams(): void
    {
        self::assertSame('zip', OzonReportExtensionDetector::detect('abc', 'APPLICATION/ZIP'));
        self::assertSame('zip', OzonReportExtensionDetector::detect('abc', '  application/zip ; charset=binary'));
    }
}
