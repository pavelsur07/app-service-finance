<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

final class McpRequestPath
{
    public static function containsSensitiveData(string $path): bool
    {
        return '/mcp' === $path
            || str_starts_with($path, '/mcp/')
            || str_starts_with($path, '/oauth/')
            || '/integrations/mcp' === $path
            || str_starts_with($path, '/integrations/mcp/');
    }
}
