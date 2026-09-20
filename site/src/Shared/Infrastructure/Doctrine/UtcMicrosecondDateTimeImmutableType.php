<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/** UTC storage for imported source timestamps in timestamp-without-timezone columns. */
final class UtcMicrosecondDateTimeImmutableType extends MicrosecondDateTimeImmutableType
{
    public const NAME = 'datetime_immutable_utc_us';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function storageTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
