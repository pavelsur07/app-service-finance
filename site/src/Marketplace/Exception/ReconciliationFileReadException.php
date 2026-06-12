<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * Не удалось открыть или прочитать файл сверки (XLSX-отчёт Ozon).
 */
final class ReconciliationFileReadException extends \RuntimeException
{
}
