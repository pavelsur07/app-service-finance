<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\CompanyRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаление шаблона роли компании.
 *
 * Проверку «шаблон никому не назначен» делает вызывающий контроллер: она управляет
 * пользовательским сообщением, а не инвариантом записи.
 */
final readonly class DeleteCompanyRoleAction
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CompanyRole $role): void
    {
        $this->em->remove($role);
        $this->em->flush();
    }
}
