<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Export;

use App\Shared\Infrastructure\Export\SpreadsheetText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpreadsheetTextTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSafe(string $input, string $expected): void
    {
        self::assertSame($expected, SpreadsheetText::safe($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'equals' => ['=HYPERLINK("x")', '\'=HYPERLINK("x")'];
        yield 'plus' => ['+7 999', "'+7 999"];
        yield 'minus' => ['-1+2', "'-1+2"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'plain text' => ['Оплата по счёту №5', 'Оплата по счёту №5'];
        yield 'formula char inside' => ['ООО "А=Б"', 'ООО "А=Б"'];
        yield 'empty' => ['', ''];
    }
}
