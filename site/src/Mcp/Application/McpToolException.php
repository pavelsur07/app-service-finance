<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class McpToolException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidInput(): self
    {
        return new self('invalid_input', 'Проверьте параметры инструмента.');
    }
}
