<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Wildberries\CommissionerReport;

use App\Cash\Service\Import\File\FileTabularReader;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxFormatValidator;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use PHPUnit\Framework\TestCase;

final class WbCommissionerXlsxFormatValidatorTest extends TestCase
{
    public function testRequiredHeadersPresentInFixture(): void
    {
        $validator = new WbCommissionerXlsxFormatValidator(new FileTabularReader());
        $filePath = $this->createFixture();

        try {
            $result = $validator->validate($filePath);
        } finally {
            @unlink($filePath);
        }

        self::assertSame([], $result->requiredMissing);
        self::assertNotSame(WbCommissionerXlsxFormatValidator::STATUS_FAILED, $result->status);
        self::assertNotSame('', $result->headersHash);
    }

    public function testInvalidFileReturnsFailedFormat(): void
    {
        $validator = new WbCommissionerXlsxFormatValidator(new FileTabularReader());
        $fixturePath = dirname(__DIR__, 3).'/Fixtures/invalid-not-xlsx.xlsx';

        self::assertFileExists($fixturePath);

        $result = $validator->validate($fixturePath);

        self::assertSame(WbCommissionerXlsxFormatValidator::STATUS_FAILED, $result->status);
        self::assertNotEmpty($result->errors);
    }

    private function createFixture(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'wb_commissioner_');
        if (false === $tempPath) {
            throw new \RuntimeException('Failed to create temporary file.');
        }

        $filePath = $tempPath.'.xlsx';
        rename($tempPath, $filePath);

        $headers = [
            'Услуги по доставке товара покупателю',
            'Эквайринг/Комиссии за организацию платежей',
            'Вознаграждение Вайлдберриз (ВВ), без НДС',
            'НДС с Вознаграждения Вайлдберриз',
            'Возмещение за выдачу и возврат товаров на ПВЗ',
            'Хранение',
            'Возмещение издержек по перевозке/по складским операциям с товаром',
            'Удержания',
            'Обоснование для оплаты',
            'Тип документа',
            'Виды логистики, штрафов и корректировок ВВ',
            'Тип платежа за Эквайринг/Комиссии за организацию платежей',
            'Дата продажи',
            'Дата заказа покупателем',
        ];

        $rows = [
            $headers,
            [
                '10.00',
                '2.50',
                '100.00',
                '20.00',
                '5.00',
                '1.00',
                '3.00',
                '0.00',
                'Эквайринг',
                'Акт',
                'Логистика WB',
                'Интернет-эквайринг',
                '2024-01-15',
                '2024-01-10',
            ],
            [
                '11.00',
                '0.00',
                '90.00',
                '18.00',
                '4.00',
                '2.00',
                '6.00',
                '1.00',
                'Логистика',
                'Счет',
                'Складская логистика',
                'Безналичный эквайринг',
                '2024-02-01',
                '2024-01-28',
            ],
        ];

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($filePath);
        foreach ($rows as $row) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($row));
        }
        $writer->close();

        return $filePath;
    }
}
