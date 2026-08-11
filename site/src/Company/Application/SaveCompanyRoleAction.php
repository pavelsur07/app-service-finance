<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Exception\CompanyRoleNameAlreadyExistsException;
use App\Company\Repository\CompanyRoleRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создание и изменение шаблона роли компании.
 *
 * Создание и изменение отличаются только исключением самой записи из проверки
 * уникальности имени, поэтому это один Action, а не два почти одинаковых
 * (тот же приём, что в SaveCounterpartyAction).
 */
final readonly class SaveCompanyRoleAction
{
    public function __construct(
        private CompanyRoleRepository $repository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string> $permissions
     */
    public function __invoke(Company $company, CompanyRole $role, array $permissions): CompanyRole
    {
        $existing = $this->repository->findOneByCompanyAndName($company, $role->getName(), $role->getId());
        if (null !== $existing) {
            throw new CompanyRoleNameAlreadyExistsException($role->getName());
        }

        $role->setCompany($company);
        $role->setPermissions($permissions);

        $this->em->persist($role);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Гонка между проверкой выше и flush: последнее слово за частичным индексом
            // uniq_company_role_company_name. Ожидаемое условие, не инцидент.
            throw new CompanyRoleNameAlreadyExistsException($role->getName(), $exception);
        }

        return $role;
    }
}
