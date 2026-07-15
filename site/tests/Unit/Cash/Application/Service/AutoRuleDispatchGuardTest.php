<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use PHPUnit\Framework\TestCase;

final class AutoRuleDispatchGuardTest extends TestCase
{
    public function testRestoresStateAfterFailure(): void
    {
        $guard = new AutoRuleDispatchGuard();

        try {
            $guard->suppress(static function () use ($guard): void {
                self::assertTrue($guard->isSuppressed());

                throw new \RuntimeException('test');
            });
            self::fail('Exception was expected.');
        } catch (\RuntimeException) {
            self::assertFalse($guard->isSuppressed());
        }
    }
}
