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
}
