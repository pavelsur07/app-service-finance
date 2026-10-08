<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

/**
 * companyId сообщения Messenger без знания его класса: getCompanyId() или публичное
 * свойство companyId. Используется для тегов Sentry и диагностики производительности.
 */
final class MessageCompanyId
{
    public static function of(object $message): ?string
    {
        if (method_exists($message, 'getCompanyId')) {
            return self::stringify($message->getCompanyId());
        }

        // isset() (а не property_exists + прямой доступ) безопасно вернёт false
        // для private/protected/неинициализированного свойства — воркер не упадёт.
        if (isset($message->companyId)) {
            return self::stringify($message->companyId);
        }

        return null;
    }

    private static function stringify(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        // PHP 8: класс с __toString() неявно реализует Stringable (UUID VO и т.п.).
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }
}
