<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Source\Wildberries\WbOrderDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbOrderDateParserTest extends TestCase
{
    /**
     * Доказательство московской зоны взято из данных, а не из документации: в
     * выгрузке один и тот же заказ имеет createdAt 19:18:04Z в marketplace и
     * date 22:18:04 без зоны в statistics.
     */
    public function testStatisticsTimeIsInterpretedAsMoscowAndReturnedInUtc(): void
    {
        $parsed = WbOrderDateParser::parseStatisticsInstant('2026-08-30T22:18:04');

        self::assertNotNull($parsed);
        self::assertSame('2026-08-30T19:18:04+00:00', $parsed->format(\DATE_ATOM));
    }

    public function testMarketplaceTimeIsAlreadyUtc(): void
    {
        $parsed = WbOrderDateParser::parseMarketplaceInstant('2026-08-30T19:18:04Z');

        self::assertNotNull($parsed);
        self::assertSame('2026-08-30T19:18:04+00:00', $parsed->format(\DATE_ATOM));
    }

    /**
     * Обе формы одного и того же момента обязаны сойтись — иначе сшивка двух
     * потоков давала бы заказу две разные даты.
     */
    public function testBothFeedsAgreeOnTheSameInstant(): void
    {
        self::assertEquals(
            WbOrderDateParser::parseMarketplaceInstant('2026-08-30T19:18:04Z'),
            WbOrderDateParser::parseStatisticsInstant('2026-08-30T22:18:04'),
        );
    }

    /**
     * WB подставляет нулевую дату вместо null — например, в cancelDate
     * неотменённого заказа. Принять её за настоящую значило бы завести заказы
     * первым годом нашей эры.
     */
    #[DataProvider('zeroSentinelProvider')]
    public function testZeroSentinelIsNotADate(string $value): void
    {
        self::assertNull(WbOrderDateParser::parseStatisticsInstant($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroSentinelProvider(): iterable
    {
        yield 'нулевая дата' => ['0001-01-01T00:00:00'];
        yield 'нулевая дата с долями' => ['0001-01-01T00:00:00.000'];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidValuesAreRejected(mixed $value): void
    {
        self::assertNull(WbOrderDateParser::parseStatisticsInstant($value));
        self::assertNull(WbOrderDateParser::parseMarketplaceInstant($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'относительная строка' => ['tomorrow'];
        yield 'несуществующее число' => ['2026-02-30T10:00:00'];
        yield '25-й час' => ['2026-09-01T25:00:00'];
        yield 'только дата' => ['2026-09-01'];
        yield 'пустая строка' => [''];
        yield 'null' => [null];
        yield 'число' => [123];
        yield 'массив' => [[]];
    }

    /**
     * Дробные доли секунды встречаются не всегда, но встречаются.
     */
    public function testFractionalSecondsAreAccepted(): void
    {
        $parsed = WbOrderDateParser::parseStatisticsInstant('2026-08-30T22:18:04.123456');

        self::assertNotNull($parsed);
        self::assertSame('2026-08-30T19:18:04+00:00', $parsed->format(\DATE_ATOM));
    }

    /**
     * Смещение в строке marketplace приводится к UTC явно: для форматов с P
     * аргумент зоны у createFromFormat() игнорируется.
     */
    public function testMarketplaceOffsetIsNormalizedToUtc(): void
    {
        $parsed = WbOrderDateParser::parseMarketplaceInstant('2026-09-01T10:00:00+03:00');

        self::assertNotNull($parsed);
        self::assertSame('2026-09-01T07:00:00+00:00', $parsed->format(\DATE_ATOM));
    }
}
