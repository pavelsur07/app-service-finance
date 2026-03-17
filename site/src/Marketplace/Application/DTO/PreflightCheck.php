<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Результат одной проверки готовности данных перед закрытием месяца.
 */
final class PreflightCheck
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool   $passed,
        public readonly bool   $blocking,   // true — блокирует закрытие
        public readonly string $message,
        public readonly mixed  $value = null, // дополнительные данные (счётчик и т.п.)
    ) {
    }

    public static function ok(string $key, string $label, string $message, mixed $value = null): self
    {
        return new self($key, $label, passed: true, blocking: false, message: $message, value: $value);
    }

    public static function warning(string $key, string $label, string $message, mixed $value = null): self
    {
        return new self($key, $label, passed: false, blocking: false, message: $message, value: $value);
    }

    public static function error(string $key, string $label, string $message, mixed $value = null): self
    {
        return new self($key, $label, passed: false, blocking: true, message: $message, value: $value);
    }
}
