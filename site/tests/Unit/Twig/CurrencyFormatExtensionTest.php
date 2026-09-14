<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\CurrencyFormatExtension;
use PHPUnit\Framework\TestCase;

final class CurrencyFormatExtensionTest extends TestCase
{
    public function testFormatsMinorUnitsWithoutFloatArithmetic(): void
    {
        $extension = new CurrencyFormatExtension();

        self::assertStringStartsWith('1 308,04', $extension->formatMinorCurrency(130804, 'RUB'));
        self::assertStringStartsWith('-1 308,04', $extension->formatMinorCurrency(-130804, 'rub'));
    }

    public function testFormatsExactStringMinorUnitsIncludingLargeTurnover(): void
    {
        $extension = new CurrencyFormatExtension();
        self::assertStringStartsWith('90 071 992 547 409,93', $extension->formatMinorCurrency('9007199254740993', 'RUB'));
        self::assertStringStartsWith('92 233 720 368 547 758,08', $extension->formatMinorCurrency('9223372036854775808', 'RUB'));
        $this->expectException(\InvalidArgumentException::class);
        $extension->formatMinorCurrency('12e3', 'RUB');
    }
}
