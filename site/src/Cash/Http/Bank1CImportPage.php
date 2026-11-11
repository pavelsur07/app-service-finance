<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Service\Import\ClientBank1CImportService;

final readonly class Bank1CImportPage
{
    public function __construct(private ClientBank1CImportService $normalizer)
    {
    }

    public function generateDownloadName(string $originalFileName): string
    {
        $baseName = pathinfo($originalFileName, \PATHINFO_FILENAME);
        $sanitized = preg_replace('/[^A-Za-z0-9\-_]+/', '_', (string) $baseName);
        if (null === $sanitized || '' === $sanitized) {
            $sanitized = 'bank1c';
        }

        return rtrim($sanitized, '_').'_preview.csv';
    }

    /**
     * Для отображения номеров счетов: убираем мусор, оставляем цифры/пробелы/буллет,
     * схлопываем и делаем компактную маску "4070 28•••• 1479".
     */
    public function sanitizeAccountForView(?string $s): ?string
    {
        if (null === $s) {
            return null;
        }
        $s = $this->normalizer->normalizeWeirdSpaces($s);
        // Оставляем цифры, пробел и "•"
        $s = preg_replace('/[^0-9• ]+/u', '', $s) ?? $s;
        // Схлопываем множественные пробелы
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        // Группы вида "28 • • • • 1479" → "28•••• 1479"
        $s = preg_replace('/(\d+)\s*(?:•\s*){2,}(\d{2,4})/u', '$1•••• $2', $s) ?? $s;

        return trim($s);
    }
}
