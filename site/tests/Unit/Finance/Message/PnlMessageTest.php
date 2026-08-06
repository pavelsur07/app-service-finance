<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Message;

use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use App\Finance\Message\RebuildPnlPeriodMessage;
use App\Finance\MessageHandler\MarkPnlPeriodDirtyHandler;
use App\Finance\MessageHandler\RebuildPnlPeriodHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class PnlMessageTest extends TestCase
{
    public function testMarkDirtyMessageExposesCompanyId(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $message = new MarkPnlPeriodDirtyMessage($companyId, 2026, 2, '', 'ingest');

        self::assertSame($companyId, $message->getCompanyId());
    }

    public function testRebuildMessageExposesCompanyId(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $message = new RebuildPnlPeriodMessage($companyId, 2026, 2);

        self::assertSame($companyId, $message->getCompanyId());
    }

    public function testCompatibilityHandlersConsumeRetiredProjectionMessages(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $logger = new NullLogger();

        (new MarkPnlPeriodDirtyHandler($logger))(new MarkPnlPeriodDirtyMessage($companyId, 2026, 2, '', 'ingest'));
        (new RebuildPnlPeriodHandler($logger))(new RebuildPnlPeriodMessage($companyId, 2026, 2));

        self::addToAssertionCount(1);
    }
}
