<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\YooKassa;

/**
 * Создание платежа: `idempotenceKey` — ID попытки оплаты (повтор в течение суток вернёт тот же
 * платёж), `metadata` вернётся в объекте платежа.
 */
final readonly class CreatePaymentRequest
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $idempotenceKey,
        public int $amountKopecks,
        public string $description,
        public string $returnUrl,
        public array $metadata,
        /** Для чека 54-ФЗ; без адреса чек не передаётся. */
        public ?string $customerEmail = null,
    ) {
    }
}
