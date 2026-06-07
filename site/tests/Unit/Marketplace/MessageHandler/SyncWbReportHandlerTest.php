<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Message\SyncWbReportMessage;
use App\Marketplace\MessageHandler\SyncWbReportHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SyncWbReportHandlerTest extends TestCase
{
    public function testLegacyMessageFailsFastWithoutWbApiLoading(): void
    {
        $reflection = new \ReflectionClass(SyncWbReportHandler::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters(), 'The legacy handler must keep only LoggerInterface dependency.');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Legacy WB report message fail-fast triggered.',
                self::callback(static function (array $context): bool {
                    self::assertSame('legacy_wb_sync_fail_fast', $context['legacy_event']);
                    self::assertSame('11111111-1111-1111-1111-111111111111', $context['company_id']);
                    self::assertSame('22222222-2222-2222-2222-222222222222', $context['connection_id']);
                    self::assertNull($context['command_class']);
                    self::assertSame(SyncWbReportMessage::class, $context['message_class']);
                    self::assertSame(SyncWbFinancialReportDayMessage::class, $context['recommended_replacement']);

                    return true;
                }),
            );

        $handler = new SyncWbReportHandler($logger);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('SyncWbFinancialReportDayMessage');

        $handler(new SyncWbReportMessage(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
        ));
    }
}
