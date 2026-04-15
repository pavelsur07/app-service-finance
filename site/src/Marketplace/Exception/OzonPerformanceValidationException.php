<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * Сигнализирует, что валидация Ozon Performance credentials не прошла.
 *
 * Два сценария:
 *  - {@see self::invalidCredentials()} — API вернул 401/403, client_id/client_secret неверны;
 *  - {@see self::apiUnavailable()} — сеть/таймаут/5xx, проверить credentials не удалось.
 *
 * В UI оба сообщения показываются пользователю как текст ошибки формы.
 */
final class OzonPerformanceValidationException extends \DomainException
{
    public static function invalidCredentials(): self
    {
        return new self('Невалидные credentials Ozon Performance API. Проверьте client_id и client_secret.');
    }

    public static function apiUnavailable(string $reason): self
    {
        return new self('Не удалось подключиться к Ozon Performance API: '.$reason);
    }
}
