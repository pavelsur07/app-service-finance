<?php

declare(strict_types=1);

namespace App\Billing\Enum;

/**
 * Код тарифа — стабильный ключ справочника `billing_tariffs`.
 * Публичные: start, base, pro. Скрытые (назначает админ): friend, support, outsourcer.
 */
enum TariffCode: string
{
    case START = 'start';
    case BASE = 'base';
    case PRO = 'pro';
    case FRIEND = 'friend';
    case SUPPORT = 'support';
    case OUTSOURCER = 'outsourcer';
}
