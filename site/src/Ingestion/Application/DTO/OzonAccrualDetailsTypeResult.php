<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог по одному «Типу начисления» отчёта кабинета.
 *
 * `outcome`: `confirmed` — группа подтверждена и записана; `conflict` — голосов
 * мало или они разошлись; `unmatched` — строки не нашлись в by-day (продажи,
 * вознаграждение — у них нет `type_id`); `unknown_group` — группы отчёта нет в
 * закрытом списке; `kept` — прежнее подтверждение сильнее нового конфликта;
 * `disputed` — отчёт подтвердил другую группу, чем уже подтверждённая: прежняя
 * действует, спор записан для админа; `weak` — у этой строки отчёта мало
 * голосов, тип решили другие строки отчёта.
 */
final readonly class OzonAccrualDetailsTypeResult
{
    public const CONFIRMED = 'confirmed';
    public const CONFLICT = 'conflict';
    public const UNMATCHED = 'unmatched';
    public const UNKNOWN_GROUP = 'unknown_group';
    public const KEPT = 'kept';
    public const DISPUTED = 'disputed';
    public const WEAK = 'weak';

    public function __construct(
        public string $xlsxTypeName,
        public string $xlsxGroupLabel,
        public ?int $typeId,
        public ?string $groupCode,
        public int $votes,
        public int $rowsTotal,
        public string $outcome,
        public bool $groupChanged = false,
    ) {
    }
}
