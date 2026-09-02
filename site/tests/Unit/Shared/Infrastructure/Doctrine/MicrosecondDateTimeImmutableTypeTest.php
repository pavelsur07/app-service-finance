<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class MicrosecondDateTimeImmutableTypeTest extends TestCase
{
    /**
     * Регрессия: стандартный `datetime_immutable` форматирует значение как
     * `Y-m-d H:i:s` независимо от точности колонки, поэтому микросекунды
     * терялись при записи и два наблюдения внутри одной секунды становились
     * неразличимы.
     */
    public function testMicrosecondsSurviveTheRoundTrip(): void
    {
        $type = new MicrosecondDateTimeImmutableType();
        $platform = new PostgreSQLPlatform();
        $value = new \DateTimeImmutable('2026-09-01 10:00:00.123456');

        $stored = $type->convertToDatabaseValue($value, $platform);
        self::assertSame('2026-09-01 10:00:00.123456', $stored);

        $restored = $type->convertToPHPValue($stored, $platform);
        self::assertNotNull($restored);
        self::assertSame('2026-09-01 10:00:00.123456', $restored->format('Y-m-d H:i:s.u'));
    }

    /**
     * Строки, записанные до перехода на этот тип, дробной части не содержат —
     * читать их всё равно нужно.
     */
    public function testValueWithoutFractionIsStillReadable(): void
    {
        $restored = (new MicrosecondDateTimeImmutableType())->convertToPHPValue('2026-09-01 10:00:00', new PostgreSQLPlatform());

        self::assertNotNull($restored);
        self::assertSame('2026-09-01 10:00:00.000000', $restored->format('Y-m-d H:i:s.u'));
    }

    /**
     * Регрессия: тип писал момент в зоне ОБЪЕКТА, а читал как местный.
     * Отметка из ClockInterface приходит в UTC, поэтому каждый round-trip
     * сдвигал её на смещение зоны — три часа для Europe/Moscow. Наблюдение,
     * прочитанное из базы, оказывалось «старше» самого себя.
     */
    public function testInstantSurvivesTheRoundTripRegardlessOfItsTimezone(): void
    {
        $type = new MicrosecondDateTimeImmutableType();
        $platform = new PostgreSQLPlatform();
        $utc = new \DateTimeImmutable('2026-09-01 10:00:00.500000', new \DateTimeZone('UTC'));

        $restored = $type->convertToPHPValue($type->convertToDatabaseValue($utc, $platform), $platform);

        self::assertNotNull($restored);
        self::assertSame(
            $utc->format('U.u'),
            $restored->format('U.u'),
            'Момент обязан вернуться тем же, в какой бы зоне ни пришёл.',
        );
    }

    /**
     * Строка, записанная ДО смены типа, обязана читаться так же, как читалась.
     *
     * Прежний тип форматировал момент в зоне объекта, а все писатели
     * `fetchedAt` берут время уже в зоне приложения (`new DateTimeImmutable()`
     * либо `applicationTime()` поверх часов). Значит накопленные строки — это
     * «стенные часы» зоны приложения, и новый тип обязан вернуть тот же момент,
     * а не сдвинуть историю.
     */
    public function testValueWrittenBeforeTheTypeChangeKeepsItsInstant(): void
    {
        $appZone = new \DateTimeZone(date_default_timezone_get());
        $written = new \DateTimeImmutable('2026-06-20 13:00:00.000000', $appZone);

        // Ровно то, что прежний тип положил бы в колонку для такого объекта.
        $legacyRow = $written->format('Y-m-d H:i:s');

        $restored = (new MicrosecondDateTimeImmutableType())->convertToPHPValue($legacyRow, new PostgreSQLPlatform());

        self::assertNotNull($restored);
        self::assertSame($written->format('U'), $restored->format('U'));
    }

    public function testNullPassesThrough(): void
    {
        $type = new MicrosecondDateTimeImmutableType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }
}
