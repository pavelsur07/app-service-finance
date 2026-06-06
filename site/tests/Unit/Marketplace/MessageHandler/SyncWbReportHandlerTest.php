<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Message\SyncWbReportMessage;
use App\Marketplace\MessageHandler\SyncWbReportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SyncWbReportHandlerTest extends TestCase
{
    public function testLegacyMessageFailsFastWithoutWbApiLoading(): void
    {
        $reflection = new \ReflectionClass(SyncWbReportHandler::class);
        self::assertNull($reflection->getConstructor(), 'The legacy handler must not keep WB API/loading dependencies.');

        $handler = new SyncWbReportHandler();

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('SyncWbFinancialReportDayMessage');

        $handler(new SyncWbReportMessage(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
        ));
    }
}
