<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Message;

use App\Inventory\Message\SyncOzonInventorySnapshotMessage;
use PHPUnit\Framework\TestCase;

final class SyncOzonInventorySnapshotMessageTest extends TestCase
{
    public function testMessageContainsOnlyScalarIdentifiersAndTriggerType(): void
    {
        $message = new SyncOzonInventorySnapshotMessage(
            companyId: '11111111-1111-1111-1111-111111111111',
            connectionId: '22222222-2222-2222-2222-222222222222',
            snapshotSessionId: '33333333-3333-3333-3333-333333333333',
            triggerType: 'manual',
        );

        self::assertSame('11111111-1111-1111-1111-111111111111', $message->companyId);
        self::assertSame('22222222-2222-2222-2222-222222222222', $message->connectionId);
        self::assertSame('33333333-3333-3333-3333-333333333333', $message->snapshotSessionId);
        self::assertSame('manual', $message->triggerType);

        self::assertFalse(property_exists($message, 'apiKey'));
        self::assertFalse(property_exists($message, 'clientSecret'));
    }
}
