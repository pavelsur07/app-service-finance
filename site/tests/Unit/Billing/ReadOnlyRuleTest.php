<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\ReadOnlyRule;
use App\Billing\Enum\AccessStatus;
use PHPUnit\Framework\TestCase;

final class ReadOnlyRuleTest extends TestCase
{
    public function testBlocksOnlyMutatingRequestsOfReadOnlyCabinet(): void
    {
        foreach (['POST', 'put', 'PATCH', 'DELETE'] as $method) {
            self::assertTrue(ReadOnlyRule::blocks($method, 'cash_transaction_new', AccessStatus::READ_ONLY), $method);
        }
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertFalse(ReadOnlyRule::blocks($method, 'cash_transaction_new', AccessStatus::READ_ONLY), $method);
        }
    }

    public function testOtherStatusesAreNotRestricted(): void
    {
        foreach ([AccessStatus::ACTIVE, AccessStatus::GRACE, AccessStatus::NONE] as $status) {
            self::assertFalse(ReadOnlyRule::blocks('POST', 'cash_transaction_new', $status), $status->value);
        }
    }

    public function testAllowedRoutesPassEvenInReadOnly(): void
    {
        foreach (['company_set_active', 'app_logout', 'app_ui_mode_switch', 'finance_report_preview_recalc'] as $route) {
            self::assertFalse(ReadOnlyRule::blocks('POST', $route, AccessStatus::READ_ONLY), $route);
        }
        self::assertTrue(ReadOnlyRule::blocks('POST', null, AccessStatus::READ_ONLY), 'маршрут неизвестен — блокируется');
    }
}
