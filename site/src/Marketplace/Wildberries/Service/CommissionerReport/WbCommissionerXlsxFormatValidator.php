<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service\CommissionerReport;

use App\Cash\Service\Import\File\FileTabularReader;
use App\Marketplace\Wildberries\Service\CommissionerReport\Dto\ValidationResultDTO;

final class WbCommissionerXlsxFormatValidator
{
    private const REQUIRED_HEADERS = [
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

    public function __construct(private readonly FileTabularReader $tabularReader)
    {
    }

    public function validate(string $absoluteFilePath): ValidationResultDTO
    {
        $headersRaw = $this->tabularReader->readHeader($absoluteFilePath);
        $headersNormalized = array_map([$this, 'normalizeHeader'], $headersRaw);
        $headersHash = hash('sha256', json_encode($headersNormalized, JSON_UNESCAPED_UNICODE));

        $warnings = [];
        $errors = [];

        if ([] === $headersNormalized) {
            $errors[] = 'Не удалось прочитать заголовки файла.';
        }

        $emptyIndexes = [];
        foreach ($headersNormalized as $index => $header) {
            if (null === $header || '' === $header) {
                $emptyIndexes[] = $index + 1;
            }
        }

        if ([] !== $emptyIndexes) {
            $warnings[] = sprintf('Пустые заголовки в колонках: %s.', implode(', ', $emptyIndexes));
        }

        $headerValues = array_values(array_filter(
            $headersNormalized,
            static fn (?string $header) => null !== $header && '' !== $header
        ));
        $headerCounts = array_count_values($headerValues);
        $duplicateHeaders = array_keys(array_filter($headerCounts, static fn (int $count) => $count > 1));

        if ([] !== $duplicateHeaders) {
            $warnings[] = sprintf('Дубликаты заголовков: %s.', implode(', ', $duplicateHeaders));
        }

        $requiredMissing = array_values(array_diff(self::REQUIRED_HEADERS, $headerValues));
        if ([] !== $requiredMissing) {
            $errors[] = sprintf('Не найдены обязательные заголовки: %s.', implode(', ', $requiredMissing));
        }

        if ([] !== $requiredMissing) {
            $status = 'failed_format';
        } elseif ([] !== $warnings) {
            $status = 'processed_with_warnings';
        } else {
            $status = 'processed';
        }

        return new ValidationResultDTO(
            $headersNormalized,
            $headersHash,
            $requiredMissing,
            $warnings,
            $errors,
            $status
        );
    }

    private function normalizeHeader(?string $header): ?string
    {
        if (null === $header) {
            return null;
        }

        $trimmed = trim($header);
        if ('' === $trimmed) {
            return null;
        }

        return preg_replace('/\s+/', ' ', $trimmed);
    }
}
