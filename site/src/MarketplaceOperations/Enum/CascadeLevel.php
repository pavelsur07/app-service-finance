<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Enum;

/**
 * Уровень каскада прибыли SKU (`REPORTS.md` §1): куда вид операции попадает в экономике.
 */
enum CascadeLevel: string
{
    case REVENUE = 'revenue';
    /** Прямые затраты SKU до рекламы. */
    case MD1 = 'md1';
    /** Реклама SKU. */
    case MD2 = 'md2';
    /** Общие затраты: разносятся по SKU ключом (D3). */
    case OVERHEAD = 'overhead';
    /** Справочно: в прибыль не входит (СПП, софинансирование). */
    case INFO = 'info';
}
