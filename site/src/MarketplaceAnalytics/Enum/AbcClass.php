<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

/**
 * Класс ABC-анализа листинга. A/B/C — доля во вкладе (80/15/5 по накопленной сумме),
 * D — убыток: листинг не вносит вклад, а забирает его. В C его не смешиваем, иначе
 * «приносит мало» и «теряет деньги» стали бы неразличимы.
 */
enum AbcClass: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}
