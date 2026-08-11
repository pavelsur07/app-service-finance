<?php

declare(strict_types=1);

namespace App\Company\Exception;

/**
 * Шаблон роли исчез между проверкой доступности и назначением участнику.
 *
 * Контроллер проверяет шаблон заранее, но между проверкой и flush его могли удалить.
 * Тогда отказ приходит от FK и транслируется в это исключение, а не в 500.
 */
final class CompanyRoleNotAvailableException extends \RuntimeException
{
    public function __construct(private readonly string $roleId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Company role "%s" is no longer available.', $roleId), 0, $previous);
    }

    public function getRoleId(): string
    {
        return $this->roleId;
    }
}
