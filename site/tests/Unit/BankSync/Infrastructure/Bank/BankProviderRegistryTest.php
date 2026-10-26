<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank;

use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use PHPUnit\Framework\TestCase;

final class BankProviderRegistryTest extends TestCase
{
    public function testKnownCodeReturnsProvider(): void
    {
        $provider = $this->createStub(BankProviderInterface::class);
        $provider->method('code')->willReturn(BankCode::ALFA);

        self::assertSame($provider, (new BankProviderRegistry([$provider]))->get(BankCode::ALFA));
    }

    public function testMissingProviderThrows(): void
    {
        $this->expectException(UnknownBankCodeException::class);
        $this->expectExceptionMessage('"alfa"');

        (new BankProviderRegistry([]))->get(BankCode::ALFA);
    }
}
