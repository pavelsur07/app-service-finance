<?php

declare(strict_types=1);

namespace App\Billing\Application\DTO;

use App\Billing\Enum\TariffCode;

/**
 * Начать онлайн-оплату. `checkoutId` — скрытый UUIDv7 формы: повтор отправки той же формы
 * возвращает ту же ссылку на оплату. `returnUrl` — куда ЮKassa вернёт клиента.
 */
final readonly class StartCheckoutCommand
{
    public function __construct(
        public string $checkoutId,
        public string $companyId,
        public TariffCode $tariffCode,
        public int $months,
        public string $returnUrl,
        public ?string $customerEmail,
    ) {
    }
}
