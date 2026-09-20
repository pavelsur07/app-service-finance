<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/** UTC storage with millisecond precision for imported source timestamps. */
final class UtcMillisecondDateTimeImmutableType extends MicrosecondDateTimeImmutableType
{
    public const NAME = 'datetime_immutable_utc_ms';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(3) WITHOUT TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        $stored = parent::convertToDatabaseValue($value, $platform);

        return null === $stored ? null : substr($stored, 0, -3);
    }

    protected function storageTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
