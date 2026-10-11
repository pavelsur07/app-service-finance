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
        yield 'thousands as in DS-Money' => ['196114.44', "196,1\u{00A0}тыс.\u{00A0}₽"];
        yield 'millions as in DS-Money' => [1200000, "1,2\u{00A0}млн\u{00A0}₽"];
        yield 'one decimal, half away from zero' => ['-15250', "\u{2212}15,3\u{00A0}тыс.\u{00A0}₽"];
        yield 'trailing zero dropped' => [2000000000, "2\u{00A0}млрд\u{00A0}₽"];
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

    public function testIncomeIsGreenAndSignedOnlyWhenAsked(): void
    {
        $extension = new VfMoneyExtension();

        self::assertSame(
            "<span class=\"vf-amount vf-amount--income\">+91\u{00A0}079<span class=\"vf-amount__minor\">,35</span></span>",
            $extension->amount('91079.35', 2, 'income', true),
        );
        self::assertStringNotContainsString('+', $extension->amount('91079.35', 2, 'income'));
        self::assertStringNotContainsString('+', $extension->amount('-11.90', 2, null, true));
    }

    public function testZeroIsMutedWithoutSignOrIncomeColour(): void
    {
        self::assertSame(
            '<span class="vf-amount vf-amount--zero">0<span class="vf-amount__minor">,00</span></span>',
            (new VfMoneyExtension())->amount('-0.004', 2, 'income', true),
        );
    }

    public function testForeignCurrencyIsAMutedSymbolAfterTheNumber(): void
    {
        $extension = new VfMoneyExtension();

        self::assertStringEndsWith("\u{00A0}<span class=\"vf-amount__currency\">$</span></span>", $extension->amount(1250, 2, null, false, 'usd'));
        self::assertStringEndsWith("\u{00A0}<span class=\"vf-amount__currency\">CNY</span></span>", $extension->amount(5000, 2, null, false, 'CNY'));
        self::assertStringNotContainsString('vf-amount__currency', $extension->amount(5000, 2, null, false, 'RUB'));
    }

    public function testAmountRejectsUnknownTone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VfMoneyExtension())->amount(1, 2, 'profit');
    }
}
