<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Callback входа в банк без действующего `state` этой сессии и компании: неизвестный,
 * уже использованный, просроченный, начатый в другой компании или для подключения, чьи
 * банк и среда с тех пор изменились (`connection_changed`). Ничего не сохраняется.
 */
final class BankAuthorizationStateInvalidException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Bank authorization state is invalid: %s.', $reason));
    }
}
