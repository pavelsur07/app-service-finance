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
use Doctrine\ORM\EntityNotFoundException;

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
            $this->em->wrapInTransaction(function () use ($member, $role, $company, $roleId): void {
                // Инвариант «остался делегированный админ» проверяется под блокировкой строки
                // компании: два одновременных понижения иначе прошли бы каждое своей проверкой
                // и вместе оставили компанию без администратора.
                $this->em->lock($company, LockMode::PESSIMISTIC_WRITE);
                // Права назначаемого шаблона тоже читаем заново: под блокировкой решение
                // должно опираться на состояние БД, а не на то, что осело в identity map.
                try {
                    $this->em->refresh($role);
                } catch (EntityNotFoundException $exception) {
                    // Шаблон удалили до блокировки — тот же осмысленный отказ, что и при
                    // нарушении FK на flush, а не 500.
                    throw new CompanyRoleNotAvailableException($roleId, $exception);
                }

                if (!$this->adminWriteGuard->keepsAdminWriteAfterMemberChange(
                    $company,
                    (string) $member->getId(),
                    $role->getPermissions(),
                )) {
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
