<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Xlsx;

use App\Ingestion\Exception\OzonAccrualDetailsFileException;
use App\Ingestion\Infrastructure\Xlsx\OzonAccrualDetailsXlsxReader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class OzonAccrualDetailsXlsxReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ozon-details-'.bin2hex(random_bytes(6)).'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReadsColumnsByNameInAnyOrder(): void
    {
        $this->write([
            ['Период: 01.08.2026-31.08.2026'],
            ['Сумма итого, руб.', 'SKU', 'Название товара', 'Тип начисления', 'Группа услуг', 'Дата начисления', 'ID начисления'],
            [-174.0, 1939477094.0, 'Товар', 'Логистика', 'Услуги доставки', 46235, '453000000000001'],
            ['-12,50', '', 'Товар', 'Подписка Premium', 'Продвижение и реклама', '03.08.2026', '453000000000002'],
            [null, 1.0, 'Товар', 'Без суммы', 'Услуги доставки', 46235, '453000000000003'],
        ]);

        $report = (new OzonAccrualDetailsXlsxReader())->read($this->path);
        $rows = $report->rows;

        self::assertCount(2, $rows);
        self::assertSame(1, $report->skippedRows);
        self::assertSame('2026-08-01', $rows[0]->date);
        self::assertSame('Услуги доставки', $rows[0]->groupLabel);
        self::assertSame('Логистика', $rows[0]->typeName);
        self::assertSame('1939477094', $rows[0]->sku);
        self::assertSame(17400, $rows[0]->amountMinor);
        self::assertSame('2026-08-03', $rows[1]->date);
        self::assertNull($rows[1]->sku);
        self::assertSame(1250, $rows[1]->amountMinor);
    }

    public function testDateCellsSpacesMinusSignAndTextSku(): void
    {
        $this->write([
            ['Период: 01.08.2026-31.08.2026'],
            ['Дата  начисления', "Группа\nуслуг", 'Тип начисления', 'Сумма итого, руб.', 'SKU'],
            [new \DateTimeImmutable('2026-08-05 10:00:00'), 'Услуги FBO', 'Размещение на складе', "\u{2212}1\u{00A0}234,56", '1939477094.0'],
            ['32.08.2026', 'Услуги FBO', 'Размещение на складе', '-1,00', '1'],
        ]);

        $report = (new OzonAccrualDetailsXlsxReader())->read($this->path);

        self::assertCount(1, $report->rows);
        self::assertSame(1, $report->skippedRows);
        self::assertSame('2026-08-05', $report->rows[0]->date);
        self::assertSame(123456, $report->rows[0]->amountMinor);
        self::assertSame('1939477094', $report->rows[0]->sku);
    }

    public function testSkuColumnIsOptional(): void
    {
        $this->write([
            ['Период: 01.08.2026-31.08.2026'],
            ['Дата начисления', 'Группа услуг', 'Тип начисления', 'Сумма итого, руб.'],
            ['01.08.2026', 'Продвижение и реклама', 'Подписка Premium', -10.0],
        ]);

        $report = (new OzonAccrualDetailsXlsxReader())->read($this->path);

        self::assertCount(1, $report->rows);
        self::assertNull($report->rows[0]->sku);
    }

    public function testMissingRequiredColumnIsRejected(): void
    {
        $this->write([
            ['Период: 01.08.2026-31.08.2026'],
            ['ID начисления', 'Дата начисления', 'Тип начисления', 'Сумма итого, руб.'],
            ['1', 46235, 'Логистика', -1.0],
        ]);

        $this->expectException(OzonAccrualDetailsFileException::class);

        (new OzonAccrualDetailsXlsxReader())->read($this->path);
    }

    public function testUnreadableFileIsRejected(): void
    {
        file_put_contents($this->path, 'not an xlsx');

        $this->expectException(OzonAccrualDetailsFileException::class);

        (new OzonAccrualDetailsXlsxReader())->read($this->path);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function write(array $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($this->path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
    }
}
