<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\YooKassa;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Настройки магазина ЮKassa из окружения. Пустые `YOOKASSA_SHOP_ID` / `YOOKASSA_SECRET_KEY` —
 * онлайн-оплата выключена: кнопки нет, уведомления не обрабатываются.
 *
 * `YOOKASSA_RECEIPT_VAT_CODE` — код ставки НДС для чека 54-ФЗ (1 — без НДС, …); пусто — чек
 * не передаётся (магазин без онлайн-чеков ЮKassa или чеки формирует сама касса).
 */
final readonly class YooKassaConfig
{
    public const DEFAULT_API_URL = 'https://api.yookassa.ru/v3';

    public function __construct(
        #[Autowire('%env(string:YOOKASSA_SHOP_ID)%')]
        public string $shopId,
        #[Autowire('%env(string:YOOKASSA_SECRET_KEY)%')]
        #[\SensitiveParameter]
        public string $secretKey,
        #[Autowire('%env(string:YOOKASSA_RECEIPT_VAT_CODE)%')]
        public string $receiptVatCode = '',
        public string $apiUrl = self::DEFAULT_API_URL,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->shopId) && '' !== trim($this->secretKey);
    }

    /** Код НДС для чека; null — чек не передаётся. */
    public function vatCode(): ?int
    {
        $code = trim($this->receiptVatCode);

        return 1 === preg_match('/^[1-9]\d?$/', $code) ? (int) $code : null;
    }
}
