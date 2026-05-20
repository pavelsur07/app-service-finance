<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Message;

use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use PHPUnit\Framework\TestCase;

final class SyncWbFinancialReportDayMessageTest extends TestCase
{
    public function testMessageContainsOnlyScalarFields(): void
    {
        $message = new SyncWbFinancialReportDayMessage(
            companyId: '11111111-1111-1111-1111-111111111111',
            connectionId: '22222222-2222-2222-2222-222222222222',
            businessDate: '2026-05-20',
            mode: 'daily',
            forceRefresh: true,
        );

        self::assertSame('11111111-1111-1111-1111-111111111111', $message->companyId);
        self::assertSame('22222222-2222-2222-2222-222222222222', $message->connectionId);
        self::assertSame('2026-05-20', $message->businessDate);
        self::assertSame('daily', $message->mode);
        self::assertTrue($message->forceRefresh);

        self::assertFalse(property_exists($message, 'apiKey'));
        self::assertFalse(property_exists($message, 'connection'));
    }
}
