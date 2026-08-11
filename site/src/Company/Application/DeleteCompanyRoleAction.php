<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\CompanyRole;
use App\Company\Exception\CompanyRoleInUseException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаление шаблона роли компании.
 *
 * Проверку «шаблон никому не назначен» делает вызывающий контроллер: она управляет
 * пользовательским сообщением, а не инвариантом записи. Окончательный инвариант держит
 * FK `ON DELETE RESTRICT` — на случай назначения, случившегося между проверкой и flush.
 */
final readonly class DeleteCompanyRoleAction
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CompanyRole $role): void
    {
        $roleId = (string) $role->getId();

        $this->em->remove($role);

        try {
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException $exception) {
            throw new CompanyRoleInUseException($roleId, $exception);
        }
    }
}
