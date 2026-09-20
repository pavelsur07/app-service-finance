<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Message;

use App\MoySklad\Message\SyncCounterpartiesMessage;
use PHPUnit\Framework\TestCase;

final class SyncCounterpartiesMessageTest extends TestCase
{
    public function testMessageCarriesOnlyTenantAndConnectionIdentifiers(): void
    {
        $message = new SyncCounterpartiesMessage('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333');
        self::assertSame(['companyId', 'connectionId', 'attempt'], array_keys(get_object_vars($message)));
        self::assertSame(0, $message->attempt);
    }

    public function testRejectsInvalidRetryCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SyncCounterpartiesMessage('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', 4);
    }
}
