<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Лист .xls слишком велик по числу ячеек для конвертации в памяти.
 *
 * Отдельно от предела по размеру файла: BIFF сжат, и небольшой файл может развернуться
 * в сотни мегабайт объектов. Считать ячейки по метаданным дешевле и точнее, чем гадать
 * по байтам.
 */
final class LegacyXlsTooManyCellsException extends \RuntimeException
{
    public function __construct(int $actualCells, int $limitCells)
    {
        parent::__construct(sprintf(
            'Лист .xls слишком большой: %s ячеек при пределе %s. Пересохраните файл в формате .xlsx.',
            number_format($actualCells, 0, ',', ' '),
            number_format($limitCells, 0, ',', ' '),
        ));
    }
}
