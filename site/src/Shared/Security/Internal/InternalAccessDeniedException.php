<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Отказ внутренней авторизации; причина — в решении политики. */
final class InternalAccessDeniedException extends AuthenticationException
{
    public function __construct(public readonly InternalAccessDecision $decision)
    {
        parent::__construct($decision->value);
    }

    public function getMessageKey(): string
    {
        return $this->decision->message();
    }
}
