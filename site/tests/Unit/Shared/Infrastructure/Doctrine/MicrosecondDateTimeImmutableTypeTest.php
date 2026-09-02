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

    public function testNullPassesThrough(): void
    {
        $type = new MicrosecondDateTimeImmutableType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }
}
