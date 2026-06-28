<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Message\CloseMonthStageMessage;
use App\Marketplace\MessageHandler\CloseMonthStageHandler;
use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// CloseMonthStageAction — final, нужен BypassFinals для createMock.
BypassFinals::allowPaths([
    '*/src/Marketplace/Application/CloseMonthStageAction.php',
]);

final class CloseMonthStageHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const ACTOR_ID = '22222222-2222-2222-2222-222222222222';

    public function testDomainExceptionIsLoggedAsWarningNotError(): void
    {
        // Регрессия follow-up: DomainException — ожидаемое доменное условие
        // («данные не готовы»), не ретраится и не инцидент → warning, не error.
        $action = $this->createMock(CloseMonthStageAction::class);
        $action->method('__invoke')->willThrowException(new \DomainException('Данные не готовы'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('[MonthClose] Domain error'),
                self::arrayHasKey('error'),
            );

        $handler = new CloseMonthStageHandler($action, $logger);

        // DomainException проглатывается (no retry) — наружу не пробрасывается.
        $handler(new CloseMonthStageMessage(
            companyId: self::COMPANY_ID,
            marketplace: 'ozon',
            year: 2026,
            month: 4,
            stage: 'sales_returns',
            actorUserId: self::ACTOR_ID,
        ));
    }
}
