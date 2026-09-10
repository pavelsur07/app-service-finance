<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Формат сырого документа — то, чем один и тот же `document_type` отличается
 * между поколениями API маркетплейса.
 *
 * Значения равны `marketplace_raw_documents.api_endpoint`: колонка заполнена на
 * всех существующих документах, поэтому отдельной миграции для различения
 * форматов не нужно. Перечислены все значения, реально встречающиеся на PROD
 * (проверено 2026-09-09).
 *
 * Нужен потому, что Ozon снял `/v3/finance/transaction/list`, а 967 документов
 * этого формата обязаны остаться обрабатываемыми: старые записи — старым
 * обработчиком, новые загрузки — новым.
 */
enum MarketplaceRawFormat: string
{
    /** Снят Ozon между 08.09 и 09.09.2026; документы остаются и переобрабатываются. */
    case OZON_TRANSACTION_LIST_V3 = 'ozon::v3/finance/transaction/list';

    /** Пришёл на замену v3. */
    case OZON_ACCRUAL_BY_DAY = 'ozon::v1/finance/accrual/by-day';

    case OZON_REALIZATION_V2 = 'ozon::v2/finance/realization';
    case OZON_MUTUAL_SETTLEMENT_V1 = '/v1/finance/mutual-settlement';

    case WB_FINANCE_SALES_REPORTS_DETAILED = 'wildberries::finance-sales-reports-detailed';

    /** Легаси WB; документы до 14.05.2026. */
    case WB_REPORT_DETAIL_BY_PERIOD = 'wildberries::reportDetailByPeriod';

    /**
     * Формат документа по его `api_endpoint`.
     *
     * Неизвестное значение даёт `null`, а не исключение: `null` означает
     * «формат не определён» и сохраняет прежнее поведение конвейера. Ронять
     * обработку исторического документа из-за незнакомой строки нельзя —
     * это превратило бы расширение справочника в инцидент.
     */
    public static function tryFromApiEndpoint(?string $apiEndpoint): ?self
    {
        if (null === $apiEndpoint || '' === trim($apiEndpoint)) {
            return null;
        }

        return self::tryFrom(trim($apiEndpoint));
    }
}
