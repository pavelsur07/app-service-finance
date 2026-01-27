<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Service\InviteTokenService;
use PHPUnit\Framework\TestCase;

final class InviteTokenServiceTest extends TestCase
{
    public function testTokenNotEmpty(): void
    {
        $service = new InviteTokenService();

        $token = $service->generatePlainToken();

        self::assertNotSame('', $token);
    }

    public function testHashStableForSameToken(): void
    {
        $service = new InviteTokenService();

        $hashOne = $service->hashToken('plain-token');
        $hashTwo = $service->hashToken('plain-token');

        self::assertSame($hashOne, $hashTwo);
    }
}
