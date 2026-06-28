<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service;

use App\Shared\Service\AppLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AppLoggerTest extends TestCase
{
    public function testLogSlowExecutionWritesWarningToLogOnly(): void
    {
        // Регрессия Stage 5: бэкдор в GlitchTip убран — медленное выполнение
        // пишется только в локальный лог как warning (AppLogger больше не знает о Sentry).
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Медленное выполнение: [CashflowReportBuilder::build]'));

        $appLogger = new AppLogger($logger);
        $appLogger->logSlowExecution('CashflowReportBuilder::build', 5000, 3000);
    }

    public function testErrorAndWarningDelegateToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('boom', self::arrayHasKey('exception'));
        $logger->expects(self::once())->method('warning')->with('careful', ['k' => 'v']);

        $appLogger = new AppLogger($logger);
        $appLogger->error('boom', new \RuntimeException('x'));
        $appLogger->warning('careful', ['k' => 'v']);
    }
}
