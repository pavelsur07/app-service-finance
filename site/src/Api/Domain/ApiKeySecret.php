<?php

declare(strict_types=1);

namespace App\Api\Domain;

final class ApiKeySecret
{
    /** @return array{publicIdentifier: string, secret: string} */
    public static function generate(): array
    {
        return ['publicIdentifier' => bin2hex(random_bytes(16)), 'secret' => bin2hex(random_bytes(32))];
    }

    public static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function format(string $publicIdentifier, #[\SensitiveParameter] string $secret): string
    {
        return 'vfd_'.$publicIdentifier.'.'.$secret;
    }

    /** @return array{publicIdentifier: string, secret: string}|null */
    public static function parse(#[\SensitiveParameter] string $token): ?array
    {
        if (1 !== preg_match('/\Avfd_([a-f0-9]{32})\.([a-f0-9]{64})\z/D', $token, $matches)) {
            return null;
        }

        return ['publicIdentifier' => $matches[1], 'secret' => $matches[2]];
    }
}
