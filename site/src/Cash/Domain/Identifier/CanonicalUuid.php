<?php

declare(strict_types=1);

namespace App\Cash\Domain\Identifier;

/**
 * Строгая проверка UUID в канонической форме 8-4-4-4-12.
 *
 * `Ramsey\Uuid\Uuid::isValid()` и `Assert::uuid()` отрезают префиксы
 * `urn:` / `uuid:`, и строка `urn:uuid:<uuid>` проходит проверку, но
 * PostgreSQL на ней падает при приведении к `uuid` — пользователь получает 500.
 * Для id из URL, формы и сессии нужна именно каноническая форма.
 */
final class CanonicalUuid
{
    private const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
