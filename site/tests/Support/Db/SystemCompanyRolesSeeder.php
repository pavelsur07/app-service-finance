<?php

declare(strict_types=1);

namespace App\Tests\Support\Db;

use App\Company\Entity\CompanyRole;
use App\Company\Security\SystemCompanyRoles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Восстанавливает системные шаблоны ролей в тестовой БД.
 *
 * DbReset восстанавливает роли сам после TRUNCATE, так что для тестов, которые
 * его вызывают, сидер стал страховкой. Он идемпотентен (существующие строки
 * пропускаются) и остаётся нужен там, где reset не выполняется вовсе.
 */
final class SystemCompanyRolesSeeder
{
    public function seed(EntityManagerInterface $em): void
    {
        foreach (SystemCompanyRoles::definitions() as $id => $definition) {
            if (null !== $em->find(CompanyRole::class, $id)) {
                continue;
            }

            $em->persist(new CompanyRole($id, $definition['name'], $definition['permissions']));
        }

        $em->flush();
    }
}
