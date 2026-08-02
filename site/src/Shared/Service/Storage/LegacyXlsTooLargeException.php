<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Исходный .xls слишком велик для конвертации.
 *
 * Отдельный класс, а не общий RuntimeException: импорт должен показать пользователю
 * внятную причину отказа и предложить пересохранить файл в .xlsx, а не оставить
 * задачу умирать в очереди по нехватке памяти.
 */
final class LegacyXlsTooLargeException extends \RuntimeException
{
    public function __construct(int $actualBytes, int $limitBytes)
    {
        // Фактический размер округляется вверх, предел — вниз. Иначе превышение
        // на байт печаталось как «20,00 МБ при пределе 20,00 МБ»: сообщение,
        // которое само себя опровергает и выглядит как баг, а не как отказ.
        parent::__construct(sprintf(
            'Файл .xls слишком большой для конвертации: %s МБ при пределе %s МБ. Пересохраните его в формате .xlsx.',
            self::formatMegabytes($actualBytes, ceil(...)),
            self::formatMegabytes($limitBytes, floor(...)),
        ));
    }

    /**
     * @param callable(float): float $round
     */
    private static function formatMegabytes(int $bytes, callable $round): string
    {
        return number_format($round($bytes / (1024 * 1024) * 100) / 100, 2, ',', ' ');
    }
}
