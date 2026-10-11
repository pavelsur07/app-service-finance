<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\VfMoneyExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VfMoneyExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|string|null, int, ?string, string}>
     */
    public static function moneyCases(): iterable
    {
        yield 'thousands use nbsp, decimals use comma' => ['1234567.891', 2, null, "1\u{00A0}234\u{00A0}567,89"];
        yield 'minus is U+2212' => ['-50000', 2, null, "\u{2212}50\u{00A0}000,00"];
        yield 'whole rubles for analytics' => [1234.5, 0, null, "1\u{00A0}235"];
        yield 'half rounds away from zero' => ['-0.125', 2, null, "\u{2212}0,13"];
        yield 'negative rounding to zero has no sign' => ['-0.004', 2, null, '0,00'];
        yield 'currency is appended with nbsp' => [100, 2, '₽', "100,00\u{00A0}₽"];
        yield 'large string keeps precision' => ['90071992547409.93', 2, null, "90\u{00A0}071\u{00A0}992\u{00A0}547\u{00A0}409,93"];
        yield 'null renders a dash' => [null, 2, null, '—'];
    }

    #[DataProvider('moneyCases')]
    public function testMoney(int|float|string|null $value, int $decimals, ?string $currency, string $expected): void
    {
        self::assertSame($expected, (new VfMoneyExtension())->money($value, $decimals, $currency));
    }

    public function testRejectsLocalisedStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VfMoneyExtension())->money('1 234,56');
    }

    /**
     * @return iterable<string, array{int|float|string, string}>
     */
    public static function shortCases(): iterable
    {
        yield 'millions as in the design system' => ['6480000', "6,48\u{00A0}млн\u{00A0}₽"];
        yield 'trailing zeros dropped' => [6500000, "6,5\u{00A0}млн\u{00A0}₽"];
        yield 'whole unit drops comma' => [2000000000, "2\u{00A0}млрд\u{00A0}₽"];
        yield 'thousands' => ['-15250', "\u{2212}15,25\u{00A0}тыс.\u{00A0}₽"];
        yield 'below a thousand stays whole' => [950.4, "950\u{00A0}₽"];
    }

    #[DataProvider('shortCases')]
    public function testMoneyShort(int|float|string $value, string $expected): void
    {
        self::assertSame($expected, (new VfMoneyExtension())->moneyShort($value));
    }

    public function testAmountMutesMinorUnits(): void
    {
        self::assertSame(
            "<span class=\"vf-amount\">\u{2212}1\u{00A0}234<span class=\"vf-amount__minor\">,50</span></span>",
            (new VfMoneyExtension())->amount('-1234.5'),
        );
    }

    public function testAmountTones(): void
    {
        $extension = new VfMoneyExtension();

        self::assertStringContainsString('vf-amount--income', $extension->amount(10, 0, 'income'));
        self::assertStringContainsString('vf-amount--loss', $extension->amount(-10, 0, 'profit'));
        self::assertStringNotContainsString('vf-amount--', $extension->amount(10, 0, 'profit'));
        self::assertStringNotContainsString('vf-amount--', $extension->amount(-10, 0));
        self::assertSame("<span class=\"vf-amount\">1\u{00A0}000</span>", $extension->amount(1000, 0));
    }

    public function testAmountRejectsUnknownTone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VfMoneyExtension())->amount(1, 2, 'expense');
    }
}
