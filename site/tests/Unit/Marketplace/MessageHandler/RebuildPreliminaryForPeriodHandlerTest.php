<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use App\Marketplace\MessageHandler\RebuildPreliminaryForPeriodHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RebuildPreliminaryForPeriodHandlerTest extends TestCase
{
    public function testHandlerCallsActionWithCommandFromMessage(): void
    {
        $captured = null;
        $action = new class($captured) extends RebuildPreliminaryForPeriodAction {
            public function __construct(private mixed &$captured)
            {
                // skip parent ctor — никаких зависимостей не вызываем
            }

            public function __invoke(RebuildPreliminaryForPeriodCommand $command): void
            {
                $this->captured = $command;
            }
        };

        $handler = new RebuildPreliminaryForPeriodHandler($action, new NullLogger());

        $handler(new RebuildPreliminaryForPeriodMessage(
            companyId:   '11111111-1111-1111-1111-111111111111',
            marketplace: 'ozon',
            year:        2026,
            month:       4,
            actorUserId: '00000000-0000-0000-0000-000000000001',
        ));

        self::assertInstanceOf(RebuildPreliminaryForPeriodCommand::class, $captured);
        self::assertSame('11111111-1111-1111-1111-111111111111', $captured->companyId);
        self::assertSame('ozon', $captured->marketplace);
        self::assertSame(2026, $captured->year);
        self::assertSame(4, $captured->month);
        self::assertSame('00000000-0000-0000-0000-000000000001', $captured->actorUserId);
    }

    public function testHandlerSwallowsDomainException(): void
    {
        $action = new class extends RebuildPreliminaryForPeriodAction {
            public function __construct()
            {
            }

            public function __invoke(RebuildPreliminaryForPeriodCommand $command): void
            {
                throw new \DomainException('preflight failed');
            }
        };

        $handler = new RebuildPreliminaryForPeriodHandler($action, new NullLogger());

        $handler(new RebuildPreliminaryForPeriodMessage(
            companyId:   '11111111-1111-1111-1111-111111111111',
            marketplace: 'ozon',
            year:        2026,
            month:       4,
            actorUserId: '00000000-0000-0000-0000-000000000001',
        ));

        // не должно бросать — DomainException гасится handler-ом без ретрая
        self::assertTrue(true);
    }

    public function testHandlerRethrowsTechnicalErrors(): void
    {
        $action = new class extends RebuildPreliminaryForPeriodAction {
            public function __construct()
            {
            }

            public function __invoke(RebuildPreliminaryForPeriodCommand $command): void
            {
                throw new \RuntimeException('db down');
            }
        };

        $handler = new RebuildPreliminaryForPeriodHandler($action, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $handler(new RebuildPreliminaryForPeriodMessage(
            companyId:   '11111111-1111-1111-1111-111111111111',
            marketplace: 'ozon',
            year:        2026,
            month:       4,
            actorUserId: '00000000-0000-0000-0000-000000000001',
        ));
    }
}
