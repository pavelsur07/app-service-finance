<?php

declare(strict_types=1);

namespace App\Company\Exception;

/**
 * Шаблон роли с таким именем уже есть у компании.
 * Дублирует частичный unique index uniq_company_role_company_name — исключение
 * даёт понятную ошибку в UI вместо 500 от нарушения ограничения БД.
 */
final class CompanyRoleNameAlreadyExistsException extends \RuntimeException
{
    public function __construct(private readonly string $roleName)
    {
        parent::__construct(sprintf('Company role "%s" already exists.', $roleName));
    }

    public function getRoleName(): string
    {
        return $this->roleName;
    }
}
