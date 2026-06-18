<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Message;

use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use App\Finance\Message\RebuildPnlPeriodMessage;
use PHPUnit\Framework\TestCase;
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
}
