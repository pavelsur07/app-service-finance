<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Doctrine;

use App\Ingestion\Domain\Contract\SecretCodec;
use App\Ingestion\Infrastructure\Security\PlaintextSecretCodec;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class EncryptedJsonType extends Type
{
    public const NAME = 'encrypted_json';

    private static ?SecretCodec $secretCodec = null;

    public static function setSecretCodec(SecretCodec $secretCodec): void
    {
        self::$secretCodec = $secretCodec;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['array', 'null']);
        }

        try {
            return self::codec()->encode($value);
        } catch (\Throwable $exception) {
            throw ConversionException::conversionFailedSerialization($value, self::NAME, $exception->getMessage(), $exception);
        }
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['string', 'null']);
        }

        try {
            return self::codec()->decode($value, PlaintextSecretCodec::KEY_VERSION);
        } catch (\Throwable $exception) {
            throw ConversionException::conversionFailed($value, self::NAME, $exception);
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    private static function codec(): SecretCodec
    {
        return self::$secretCodec ??= new PlaintextSecretCodec();
    }
}
