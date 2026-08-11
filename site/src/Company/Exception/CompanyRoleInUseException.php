<?php

declare(strict_types=1);

namespace App\Company\Exception;

/**
 * Шаблон роли назначен участникам или активным приглашениям и не может быть удалён.
 *
 * Контроллер проверяет это заранее, чтобы дать понятное сообщение, но между проверкой
 * и удалением возможно конкурентное назначение. Тогда отказ приходит от FK
 * (`ON DELETE RESTRICT`) и транслируется в это исключение вместо 500.
 */
final class CompanyRoleInUseException extends \RuntimeException
{
    public function __construct(private readonly string $roleId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Company role "%s" is still assigned.', $roleId), 0, $previous);
    }

    public function getRoleId(): string
    {
        return $this->roleId;
    }
}
