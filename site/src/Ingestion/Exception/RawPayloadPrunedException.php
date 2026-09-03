<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

/**
 * Сырьё существует, но его полезная нагрузка удалена по политике хранения.
 *
 * Отдельно от {@see RawRecordNotFoundException}: «записи нет» и «запись есть,
 * а нагрузки уже нет» — разные факты с разной реакцией. Первое означает
 * неверный идентификатор или чужую компанию, второе — что данные вышли за окно
 * горячего хранения, и это ожидаемо, а не ошибка вызывающего.
 */
final class RawPayloadPrunedException extends \RuntimeException
{
}
