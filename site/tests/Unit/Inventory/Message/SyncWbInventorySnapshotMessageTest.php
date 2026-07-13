<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Message;

use App\Inventory\Message\SyncWbInventorySnapshotMessage;
use PHPUnit\Framework\TestCase;

final class SyncWbInventorySnapshotMessageTest extends TestCase
{
    public function testCarriesScalarContext(): void
    {
        $message = new SyncWbInventorySnapshotMessage('company', 'connection', 'session', 'manual');

        self::assertSame('company', $message->companyId);
        self::assertSame('connection', $message->connectionId);
        self::assertSame('session', $message->snapshotSessionId);
        self::assertSame('manual', $message->triggerType);
    }
}
