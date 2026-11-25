<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

/** Статус прогона оценки складов и итог проводки счёта за день. */
enum StoreBalanceStatus: string
{
    case VALUING = 'valuing';
    case VALUED = 'valued';
    case POSTED = 'posted';
    case UNCHANGED = 'unchanged';
    case PARTIAL = 'partial';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::VALUING => 'Считается',
            self::VALUED => 'Оценено, запись в Баланс выключена',
            self::POSTED => 'Проведено в Баланс',
            self::UNCHANGED => 'Остаток не изменился',
            self::PARTIAL => 'Проведено частично',
            self::SKIPPED => 'Не проведено',
            self::FAILED => 'Ошибка',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::POSTED, self::UNCHANGED => 'success',
            self::VALUING, self::VALUED => 'secondary',
            self::PARTIAL, self::SKIPPED => 'warning',
            self::FAILED => 'danger',
        };
    }

    public static function errorLabel(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'forbidden' => 'У токена МойСклад нет права «Видеть себестоимость».',
            'auth' => 'Подключение МойСклад не авторизовано.',
            'rate_limited', 'temporary' => 'МойСклад временно недоступен, повтор автоматически.',
            'actor_forbidden' => 'У автора записи нет права проводить в Балансе.',
            default => 'Ответ МойСклад не распознан.',
        };
    }
}
