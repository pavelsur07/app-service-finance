<?php

declare(strict_types=1);

namespace App\Company\Enum;

/**
 * Откуда отображаемое название контрагента (docs/plan/counterparty-display-name.md).
 * MANUAL главнее правил: пересчёт его не трогает.
 */
enum CounterpartyDisplayNameSource: string
{
    case AUTO = 'auto';
    case MANUAL = 'manual';
}
