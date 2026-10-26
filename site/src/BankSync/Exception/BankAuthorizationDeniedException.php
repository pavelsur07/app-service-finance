<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Банк вернул в callback ошибку вместо кода: клиент отказался, вход истёк и т.п.
 * `error` — код OAuth, только если он выглядит как код.
 */
final class BankAuthorizationDeniedException extends \RuntimeException
{
    public readonly ?string $error;

    public function __construct(?string $error)
    {
        $this->error = null !== $error && 1 === preg_match('/^[a-z_]{1,64}$/', $error) ? $error : null;

        parent::__construct(sprintf('Bank authorization was denied: %s.', $this->error ?? 'unknown'));
    }
}
