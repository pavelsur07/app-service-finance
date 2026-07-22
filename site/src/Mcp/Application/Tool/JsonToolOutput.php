<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

trait JsonToolOutput
{
    private function json(mixed $payload): string
    {
        return json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }
}
