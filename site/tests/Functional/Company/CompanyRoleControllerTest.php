<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyRoleRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\SystemCompanyRoles;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Db\SystemCompanyRolesSeeder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyRoleControllerTest extends WebTestCaseBase
{
    public function testOwnerCanListSystemAndCompanyRoles(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $customRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $company,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($customRole);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Шаблоны доступа');
        self::assertSelectorTextContains('table', 'Полный доступ');

        $companyRoleTable = $crawler->filter('.card')->reduce(static function ($node) {
            return str_contains((string) $node->filter('.card-title')->text(), 'Шаблоны компании');
        })->filter('table');
        self::assertCount(1, $companyRoleTable);
        self::assertStringContainsString('Бухгалтер', (string) $companyRoleTable->text());
    }

    public function testOwnerCanCreateRole(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', '/company/roles/create', [
            'company_role' => [
                'name' => 'Бухгалтер',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        /** @var CompanyRoleRepository $roleRepository */
        $roleRepository = self::getContainer()->get(CompanyRoleRepository::class);
        $roles = $roleRepository->findBy(['company' => $company]);
        self::assertCount(1, $roles);
        self::assertSame('Бухгалтер', $roles[0]->getName());
        self::assertSame(AccessLevel::WRITE->value, $roles[0]->getPermissions()[Module::FINANCE->value]);
    }

    public function testOwnerCanEditOwnRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $customRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::READ->value],
            $company,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($customRole);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/company/roles/%s/edit', $customRole->getId()));
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', sprintf('/company/roles/%s/update', $customRole->getId()), [
            'company_role' => [
                'name' => 'Главный бухгалтер',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyRole::class, $customRole->getId());
        self::assertInstanceOf(CompanyRole::class, $updated);
        self::assertSame('Главный бухгалтер', $updated->getName());
        self::assertSame(AccessLevel::WRITE->value, $updated->getPermissions()[Module::FINANCE->value]);
    }

    public function testOwnerCannotDeleteAssignedRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $customRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $company,
        );
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withAccessRole($customRole)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($customRole);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/roles/%s/delete', $customRole->getId()), [
            '_token' => $this->csrfToken($client, 'delete_role_'.$customRole->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $existing = $this->em()->find(CompanyRole::class, $customRole->getId());
        self::assertInstanceOf(CompanyRole::class, $existing);
    }

    public function testOwnerCanDeleteUnassignedRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $customRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $company,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($customRole);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/roles/%s/delete', $customRole->getId()), [
            '_token' => $this->csrfToken($client, 'delete_role_'.$customRole->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $existing = $this->em()->find(CompanyRole::class, $customRole->getId());
        self::assertNull($existing);
    }

    public function testNonOwnerCannotAccessRoles(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/company/roles');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCannotEditRoleOfAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $otherOwner = UserBuilder::aUser()->withIndex(2)->withEmail('other@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $otherRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Чужой шаблон',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $otherCompany,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($otherOwner);
        $em->persist($company);
        $em->persist($otherCompany);
        $em->persist($otherRole);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', sprintf('/company/roles/%s/update', $otherRole->getId()), [
            'company_role' => [
                'name' => 'Взломанный шаблон',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyRole::class, $otherRole->getId());
        self::assertInstanceOf(CompanyRole::class, $updated);
        self::assertSame('Чужой шаблон', $updated->getName());
    }

    public function testOwnerCannotDeleteRoleOfAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $otherOwner = UserBuilder::aUser()->withIndex(2)->withEmail('other@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $otherRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Чужой шаблон',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $otherCompany,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($otherOwner);
        $em->persist($company);
        $em->persist($otherCompany);
        $em->persist($otherRole);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/roles/%s/delete', $otherRole->getId()), [
            '_token' => $this->csrfToken($client, 'delete_role_'.$otherRole->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $existing = $this->em()->find(CompanyRole::class, $otherRole->getId());
        self::assertInstanceOf(CompanyRole::class, $existing);
    }

    public function testOwnerCannotEditSystemRole(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $fullAccessRoleId = SystemCompanyRoles::FULL_ACCESS_ID;
        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', sprintf('/company/roles/%s/update', $fullAccessRoleId), [
            'company_role' => [
                'name' => 'Взломанный системный шаблон',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyRole::class, $fullAccessRoleId);
        self::assertInstanceOf(CompanyRole::class, $updated);
        self::assertNotSame('Взломанный системный шаблон', $updated->getName());
    }

    public function testOwnerCannotCreateRoleWithEmptyName(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', '/company/roles/create', [
            'company_role' => [
                'name' => '',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invalid-feedback', 'Введите название шаблона');

        /** @var CompanyRoleRepository $roleRepository */
        $roleRepository = self::getContainer()->get(CompanyRoleRepository::class);
        self::assertCount(0, $roleRepository->findBy(['company' => $company]));
    }

    public function testCompanyDeletionStillCascadesUnassignedRoles(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $customRole = new CompanyRole(
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $company,
        );

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($customRole);
        $em->flush();
        $companyId = (string) $company->getId();
        $roleId = (string) $customRole->getId();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        // company_members.role_id переведён в RESTRICT, но company_role.company_id остаётся
        // CASCADE: удаление компании без участников обязано по-прежнему сносить её шаблоны.
        $client->request('POST', sprintf('/company/%s/delete', $companyId), [
            '_token' => $this->csrfToken($client, 'delete'.$companyId),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertNull($this->em()->find(CompanyRole::class, $roleId));
    }

    public function testOwnerCannotRemoveAdminWriteFromLastAdminRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('admin-member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $adminRole = new CompanyRole(
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'Администратор',
            [Module::ADMIN->value => AccessLevel::WRITE->value],
            $company,
        );
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withAccessRole($adminRole)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($adminRole);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/company/roles/%s/edit', $adminRole->getId()));
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        // Снятие admin:write у шаблона применяется сразу ко всем, кому он назначен,
        // поэтому защита последнего админа обязана работать и на редактировании шаблона.
        $client->request('POST', sprintf('/company/roles/%s/update', $adminRole->getId()), [
            'company_role' => [
                'name' => 'Администратор',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::READ->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::NONE->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $this->em()->clear();
        $unchanged = $this->em()->find(CompanyRole::class, $adminRole->getId());
        self::assertInstanceOf(CompanyRole::class, $unchanged);
        self::assertSame(
            AccessLevel::WRITE->value,
            $unchanged->getPermissions()[Module::ADMIN->value] ?? null,
            'admin:write сняли у последнего административного шаблона.',
        );
    }

    public function testRevokedInviteDoesNotBlockRoleDeletion(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $role = new CompanyRole(
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'Временный',
            [Module::FINANCE->value => AccessLevel::READ->value],
            $company,
        );
        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withAccessRole($role)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($role);
        $em->persist($invite);
        $em->flush();

        // Отзыв освобождает ссылку на шаблон: иначе FK RESTRICT запретил бы удаление навсегда.
        $invite->revoke();
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/roles/%s/delete', $role->getId()), [
            '_token' => $this->csrfToken($client, 'delete_role_'.$role->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertNull($this->em()->find(CompanyRole::class, $role->getId()));
    }

    public function testOwnerCannotCreateRoleWithDuplicateName(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $existing = new CompanyRole(
            '88888888-8888-4888-8888-888888888888',
            'Бухгалтер',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
        );
        $existing->setCompany($company);

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($existing);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        // Регистр намеренно другой: проверка регистронезависимая, строже точного индекса.
        $client->request('POST', '/company/roles/create', [
            'company_role' => [
                'name' => 'бухгалтер',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::READ->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::NONE->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invalid-feedback', 'Шаблон с таким названием уже есть');

        /** @var CompanyRoleRepository $roleRepository */
        $roleRepository = self::getContainer()->get(CompanyRoleRepository::class);
        self::assertCount(1, $roleRepository->findBy(['company' => $company]));
    }

    public function testOwnerCannotCreateRoleWithOverlongName(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/roles/new');
        $token = (string) $crawler->filter('input[name="company_role[_token]"]')->attr('value');

        $client->request('POST', '/company/roles/create', [
            'company_role' => [
                'name' => str_repeat('a', 129),
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::MARKETPLACE->value => AccessLevel::NONE->value,
                    Module::DEALS->value => AccessLevel::NONE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::ADMIN->value => AccessLevel::NONE->value,
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invalid-feedback', '128');

        /** @var CompanyRoleRepository $roleRepository */
        $roleRepository = self::getContainer()->get(CompanyRoleRepository::class);
        self::assertCount(0, $roleRepository->findBy(['company' => $company]));
    }
}
