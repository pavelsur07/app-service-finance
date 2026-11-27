<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure;

use App\Marketplace\Application\DTO\ListingTagsExportRow;
use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Exception\InvalidListingTagsFileException;
use App\Marketplace\Infrastructure\Export\ListingTagsXlsxExporter;
use App\Marketplace\Infrastructure\Import\ListingTagsFileReader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class ListingTagsFileRoundTripTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testExportedFileReadsBackWithTagsAndTextCells(): void
    {
        (new ListingTagsXlsxExporter())->export([
            new ListingTagsExportRow('0193a000-0000-7000-8000-000000000001', 'ozon', '=HYPERLINK("x")', '00123', 'M', 'Платье', ['Зима', 'Лето']),
            new ListingTagsExportRow('0193a000-0000-7000-8000-000000000002', 'wildberries', null, '45', 'UNKNOWN', null, []),
        ], ['Зима', 'Лето'], $this->file);

        $rows = (new ListingTagsFileReader())->read($this->file);

        self::assertEquals([
            new ListingTagsFileRow(2, '0193a000-0000-7000-8000-000000000001', 'Зима; Лето'),
            new ListingTagsFileRow(3, '0193a000-0000-7000-8000-000000000002', ''),
        ], $rows);

        $zip = new \ZipArchive();
        $zip->open($this->file);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringNotContainsString('<f>', $xml, 'Текст из листинга не стал формулой');
        self::assertStringContainsString('00123', $xml, 'SKU не потерял ведущие нули');
    }

    public function testColumnsAreFoundByHeaderInAnyOrder(): void
    {
        $this->write([['Теги', 'Название', 'LISTING_ID'], ['Осень', 'x', ' 0193a000-0000-7000-8000-000000000003 '], [null, null, null]]);

        self::assertEquals(
            [new ListingTagsFileRow(2, '0193a000-0000-7000-8000-000000000003', 'Осень')],
            (new ListingTagsFileReader())->read($this->file),
        );
    }

    public function testFileWithoutRequiredColumnsIsRejected(): void
    {
        $this->write([['Артикул', 'Теги'], ['A-1', 'Зима']]);

        $this->expectException(InvalidListingTagsFileException::class);
        (new ListingTagsFileReader())->read($this->file);
    }

    public function testTooManyRowsAreRejected(): void
    {
        $this->write([['listing_id', 'Теги'], ['a', 'x'], ['b', 'y'], ['c', 'z']]);

        $this->expectException(InvalidListingTagsFileException::class);
        (new ListingTagsFileReader(maxRows: 2))->read($this->file);
    }

    /**
     * @param list<list<string|null>> $rows
     */
    private function write(array $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($this->file);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
    }
}
