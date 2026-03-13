<?php

declare(strict_types=1);

namespace App\Shared\Security\Contract;

final class EncryptionFieldNamingContract
{
    public const ENCRYPTED_SUFFIX = 'encrypted';
    public const KEY_VERSION_SUFFIX = 'key_version';
    public const ENCRYPTED_AT_SUFFIX = 'encrypted_at';

    public static function encryptedColumn(string $field): string
    {
        return sprintf('%s_%s', $field, self::ENCRYPTED_SUFFIX);
    }

    public static function keyVersionColumn(string $field): string
    {
        return sprintf('%s_%s', $field, self::KEY_VERSION_SUFFIX);
    }

    public static function encryptedAtColumn(string $field): string
    {
        return sprintf('%s_%s', $field, self::ENCRYPTED_AT_SUFFIX);
    }
}
