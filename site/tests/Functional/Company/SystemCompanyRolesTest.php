<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\SystemCompanyRoles;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class SystemCompanyRolesTest extends WebTestCaseBase
{
    /**
     * После DbReset системные шаблоны восстановлены в точности по
     * SystemCompanyRoles::definitions() — не только по количеству (это проверяет
     * DbResetTest), но и по имени и набору прав каждого шаблона.
     */
    public function testRestoredTemplatesMatchDefinitionsExactly(): void
    {
        static::createClient();
        $this->resetDb();

        $roles = $this->em()->getRepository(CompanyRole::class)->findBy(['company' => null]);

        $definitions = SystemCompanyRoles::definitions();
        self::assertCount(\count($definitions), $roles);

        $byId = [];
        foreach ($roles as $role) {
            $byId[$role->getId()] = $role;
        }

        foreach ($definitions as $id => $definition) {
            self::assertArrayHasKey($id, $byId);
            self::assertSame($definition['name'], $byId[$id]->getName());
            self::assertEquals($definition['permissions'], $byId[$id]->getPermissions());
        }
    }

    public function testOwnerMembershipCreatorAssignsSystemOwnerTemplate(): void
    {
        static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('roles-owner@example.test')
            ->build();
        $this->em()->persist($owner);
        $this->em()->flush();

        $creator = static::getContainer()->get(CompanyOwnerMembershipCreator::class);
        $company = $creator->createCompany($owner, 'Roles Test LLC');
        $this->em()->flush();

        $member = static::getContainer()->get(CompanyMemberRepository::class)
            ->findActiveOneByCompanyAndUser($company, $owner);

        self::assertNotNull($member);
        self::assertNotNull($member->getAccessRole());
        self::assertSame(SystemCompanyRoles::OWNER_ID, $member->getAccessRole()->getId());
    }
}
