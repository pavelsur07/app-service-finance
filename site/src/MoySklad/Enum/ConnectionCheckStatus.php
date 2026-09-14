<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum ConnectionCheckStatus: string
{
    case UNVERIFIED = 'unverified';
    case CONNECTED = 'connected';
    case INVALID_TOKEN = 'invalid_token';
    case FORBIDDEN = 'forbidden';
    case TARIFF_RESTRICTED = 'tariff_restricted';
    case UNSUPPORTED_TOKEN = 'unsupported_token';
    case RATE_LIMITED = 'rate_limited';
    case UNAVAILABLE = 'unavailable';
    case INVALID_RESPONSE = 'invalid_response';

    public function message(): string
    {
        return match ($this) {
            self::UNVERIFIED => 'Подключение ещё не проверено.',
            self::CONNECTED => 'Доступ к МойСклад подтверждён.',
            self::INVALID_TOKEN => 'Токен не принят. Проверьте токен доступа.',
            self::FORBIDDEN => 'Недостаточно прав для доступа к МойСклад.',
            self::TARIFF_RESTRICTED => 'Доступ к API ограничен тарифом МойСклад.',
            self::UNSUPPORTED_TOKEN => 'Этот тип токена не поддерживается. Используйте токен пользователя.',
            self::RATE_LIMITED => 'Превышен лимит запросов МойСклад. Повторите проверку позже.',
            self::UNAVAILABLE => 'МойСклад временно недоступен. Повторите проверку позже.',
            self::INVALID_RESPONSE => 'МойСклад вернул некорректный ответ. Повторите проверку позже.',
        };
    }
}
