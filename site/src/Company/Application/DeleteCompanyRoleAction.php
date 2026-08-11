<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\CompanyRole;
use App\Company\Exception\CompanyRoleInUseException;
use App\Company\Repository\CompanyInviteRepository;
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
        private CompanyInviteRepository $inviteRepository,
    ) {
    }

    public function __invoke(CompanyRole $role): void
    {
        $roleId = (string) $role->getId();

        try {
            $this->em->wrapInTransaction(function () use ($role): void {
                // Приглашения, которые уже нельзя принять, ссылку освобождают: иначе просроченное
                // приглашение держало бы FK RESTRICT вечно.
                $this->inviteRepository->releaseAccessRoleFromUnusableInvites($role, new \DateTimeImmutable());

                $this->em->remove($role);
                $this->em->flush();
            });
        } catch (ForeignKeyConstraintViolationException $exception) {
            throw new CompanyRoleInUseException($roleId, $exception);
        }
    }
}
