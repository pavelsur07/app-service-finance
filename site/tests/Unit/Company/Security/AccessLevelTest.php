<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\AccessLevel;
use PHPUnit\Framework\TestCase;

final class AccessLevelTest extends TestCase
{
    public function testWriteCoversReadAndWrite(): void
    {
        self::assertTrue(AccessLevel::WRITE->atLeast(AccessLevel::WRITE));
        self::assertTrue(AccessLevel::WRITE->atLeast(AccessLevel::READ));
        self::assertTrue(AccessLevel::WRITE->atLeast(AccessLevel::NONE));
    }

    public function testReadCoversOnlyRead(): void
    {
        self::assertFalse(AccessLevel::READ->atLeast(AccessLevel::WRITE));
        self::assertTrue(AccessLevel::READ->atLeast(AccessLevel::READ));
        self::assertTrue(AccessLevel::READ->atLeast(AccessLevel::NONE));
    }

    public function testNoneCoversNothing(): void
    {
        self::assertFalse(AccessLevel::NONE->atLeast(AccessLevel::WRITE));
        self::assertFalse(AccessLevel::NONE->atLeast(AccessLevel::READ));
        self::assertTrue(AccessLevel::NONE->atLeast(AccessLevel::NONE));
    }
}
