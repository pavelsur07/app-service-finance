<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Exception\CompanyRoleNotAvailableException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Назначение участнику шаблона доступа.
 *
 * Action владеет записью; проверки владельца, принадлежности шаблона компании и
 * «последний admin:write» остаются в контроллере — они выбирают текст ошибки
 * для пользователя, а не инвариант записи.
 */
final readonly class AssignCompanyMemberAccessRoleAction
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CompanyMember $member, CompanyRole $role): void
    {
        $roleId = (string) $role->getId();

        $member->setAccessRole($role);

        $this->em->persist($member);

        try {
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException $exception) {
            // Обратное направление гонки: шаблон удалили между проверкой в контроллере
            // и этим flush. Ожидаемое условие, не инцидент — 500 отдавать нельзя.
            throw new CompanyRoleNotAvailableException($roleId, $exception);
        }
    }
}
