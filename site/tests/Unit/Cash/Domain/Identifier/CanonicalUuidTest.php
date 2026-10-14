<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Domain\Identifier;

use App\Cash\Domain\Identifier\CanonicalUuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalUuidTest extends TestCase
{
    #[DataProvider('validProvider')]
    public function testAcceptsCanonicalForm(string $value): void
    {
        self::assertTrue(CanonicalUuid::isValid($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'v4' => ['0b6f5a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b'];
        yield 'v7' => ['01926f3a-7b8c-7d9e-8f0a-1b2c3d4e5f60'];
        yield 'upper case' => ['0B6F5A3E-1C2D-4E5F-8A9B-0C1D2E3F4A5B'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsAnythingPostgresCannotCast(string $value): void
    {
        self::assertFalse(CanonicalUuid::isValid($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['not-a-uuid'];
        yield 'urn prefix' => ['urn:uuid:0b6f5a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b'];
        yield 'uuid prefix' => ['uuid:0b6f5a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b'];
        yield 'braces' => ['{0b6f5a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b}'];
        yield 'trailing newline' => ["0b6f5a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b\n"];
        yield 'no dashes' => ['0b6f5a3e1c2d4e5f8a9b0c1d2e3f4a5b'];
    }
}
