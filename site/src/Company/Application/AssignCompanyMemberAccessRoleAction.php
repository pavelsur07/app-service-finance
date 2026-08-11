<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Domain\Service\CompanyAdminWriteGuard;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Exception\CompanyRoleNotAvailableException;
use App\Company\Exception\LastCompanyAdminException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Назначение участнику шаблона доступа.
 *
 * Action владеет записью и инвариантом «в компании остаётся делегированный админ»:
 * проверка идёт под блокировкой строки компании в одной транзакции с записью.
 * Контроллер делает ту же проверку заранее — только чтобы показать понятное сообщение.
 * Проверки владельца и принадлежности шаблона компании остаются в контроллере.
 */
final readonly class AssignCompanyMemberAccessRoleAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyAdminWriteGuard $adminWriteGuard,
    ) {
    }

    public function __invoke(CompanyMember $member, CompanyRole $role): void
    {
        $roleId = (string) $role->getId();
        $company = $member->getCompany();

        try {
            $this->em->wrapInTransaction(function () use ($member, $role, $company): void {
                // Инвариант «остался делегированный админ» проверяется под блокировкой строки
                // компании: два одновременных понижения иначе прошли бы каждое своей проверкой
                // и вместе оставили компанию без администратора.
                $this->em->lock($company, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($member);

                if (!$this->adminWriteGuard->keepsAdminWriteAfterMemberChange($company, $member, $role)) {
                    throw new LastCompanyAdminException();
                }

                $member->setAccessRole($role);
                $this->em->persist($member);
                $this->em->flush();
            });
        } catch (ForeignKeyConstraintViolationException $exception) {
            // Обратное направление гонки: шаблон удалили между проверкой в контроллере
            // и этим flush. Ожидаемое условие, не инцидент — 500 отдавать нельзя.
            throw new CompanyRoleNotAvailableException($roleId, $exception);
        }
    }
}
