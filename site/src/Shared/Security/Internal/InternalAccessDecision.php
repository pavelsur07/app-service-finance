<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

/**
 * Итог проверки запроса к `/internal/*`: код ответа и стабильный код ошибки.
 */
enum InternalAccessDecision: string
{
    case GRANTED = 'granted';
    /** Token или allowlist не заданы: endpoint закрыт для всех (fail-closed). */
    case DISABLED = 'internal_api_disabled';
    case AUTH_REQUIRED = 'internal_auth_required';
    case IP_FORBIDDEN = 'internal_ip_forbidden';

    public function httpStatus(): int
    {
        return match ($this) {
            self::GRANTED => 200,
            self::AUTH_REQUIRED => 401,
            self::DISABLED, self::IP_FORBIDDEN => 403,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::GRANTED => 'OK',
            self::DISABLED => 'Внутренний API не настроен',
            self::AUTH_REQUIRED => 'Требуется внутренний токен',
            self::IP_FORBIDDEN => 'Адрес не входит в список разрешённых',
        };
    }
}
