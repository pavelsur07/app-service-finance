<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Enum;

use App\Marketplace\Enum\MarketplaceRawFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Значения — не выдуманные: это все `api_endpoint`, реально встречающиеся в
 * `marketplace_raw_documents` на PROD (проверено 2026-09-09 через codex-psql-ro).
 */
final class MarketplaceRawFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, MarketplaceRawFormat}>
     */
    public static function knownEndpoints(): iterable
    {
        yield 'ozon legacy transaction list' => ['ozon::v3/finance/transaction/list', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3];
        yield 'ozon accrual by-day' => ['ozon::v1/finance/accrual/by-day', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY];
        yield 'ozon realization' => ['ozon::v2/finance/realization', MarketplaceRawFormat::OZON_REALIZATION_V2];
        yield 'ozon mutual settlement' => ['/v1/finance/mutual-settlement', MarketplaceRawFormat::OZON_MUTUAL_SETTLEMENT_V1];
        yield 'wb finance sales reports' => ['wildberries::finance-sales-reports-detailed', MarketplaceRawFormat::WB_FINANCE_SALES_REPORTS_DETAILED];
        yield 'wb legacy report by period' => ['wildberries::reportDetailByPeriod', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD];
    }

    #[DataProvider('knownEndpoints')]
    public function testMapsEndpointStoredOnProduction(string $endpoint, MarketplaceRawFormat $expected): void
    {
        self::assertSame($expected, MarketplaceRawFormat::tryFromApiEndpoint($endpoint));
    }

    public function testUnknownEndpointDegradesToNullInsteadOfThrowing(): void
    {
        // Документ старше введения формата или из источника, которого ещё нет.
        // null означает «формат неизвестен» и оставляет прежнее поведение.
        self::assertNull(MarketplaceRawFormat::tryFromApiEndpoint('ozon::v9/что-то/новое'));
        self::assertNull(MarketplaceRawFormat::tryFromApiEndpoint(null));
        self::assertNull(MarketplaceRawFormat::tryFromApiEndpoint(''));
    }
}
