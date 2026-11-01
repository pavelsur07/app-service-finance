<?php

declare(strict_types=1);

namespace App\Billing\Application\DTO;

use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;

/**
 * Зачисление оплаты. `paymentId` — ключ повтора от вызывающего (UUIDv7, сгенерированный
 * при показе формы или из внешнего события), он же ID записи оплаты.
 */
final readonly class RegisterPaymentCommand
{
    public function __construct(
        public string $paymentId,
        public string $companyId,
        public TariffCode $tariffCode,
        public int $months,
        public int $amountKopecks,
        public \DateTimeImmutable $receivedAt,
        public PaymentSource $source,
        public ?string $externalId = null,
    ) {
    }
}
