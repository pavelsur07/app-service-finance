<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Файл .xls не удалось прочитать: его нет, нет прав или он повреждён.
 *
 * Отдельный класс, а не LegacyXlsTooLargeException с нулевым размером: пропавший
 * файл, объявленный «слишком большим на 0 МБ», уводит разбор в сторону размеров,
 * тогда как чинить надо доставку файла.
 */
final class LegacyXlsUnreadableException extends \RuntimeException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'Не удалось прочитать файл .xls (%s): файл недоступен или повреждён.',
            basename($path),
        ), 0, $previous);
    }
}
