<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccess;
use App\Company\Security\SystemCompanyRoles;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Db\SystemCompanyRolesSeeder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ModuleAccessTest extends WebTestCaseBase
{
    private const FINANCE_URL = '/finance/cash-transactions/';
    private const MARKETPLACE_URL = '/marketplace';

    public function testOwnerCanOpenFinanceAndMarketplacePages(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $owner] = $this->seedCompanyWithOwner();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $client->request('GET', self::MARKETPLACE_URL);
        self::assertResponseIsSuccessful();
    }

    public function testLegacyOperatorMemberWithoutAccessRoleKeepsFullAccess(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company] = $this->seedCompanyWithOwner();
        $memberUser = $this->seedMember($company, CompanyMember::ROLE_OPERATOR, null);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $client->request('GET', self::MARKETPLACE_URL);
        self::assertResponseIsSuccessful();
    }

    public function testMemberWithFinanceOnlyRoleCanOpenFinanceButNotMarketplace(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        [$company] = $this->seedCompanyWithOwner();

        // Системный шаблон «Финансист»: finance write + catalog read.
        $financeOnlyRole = $this->em()->find(CompanyRole::class, SystemCompanyRoles::FINANCE_ID);
        self::assertInstanceOf(CompanyRole::class, $financeOnlyRole);

        $memberUser = $this->seedMember($company, CompanyMember::ROLE_OPERATOR, $financeOnlyRole);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $client->request('GET', self::MARKETPLACE_URL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testReadOnlyFinanceRoleDeniesWriteAttribute(): void
    {
        static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedCompanyWithReadOnlyFinanceMember();

        // Прямая проверка voter'а через AuthorizationChecker: read есть, write — нет.
        $container = static::getContainer();
        $requestStack = $container->get('request_stack');
        $session = new Session(new MockArraySessionStorage());
        $session->set('active_company_id', $company->getId());
        $request = Request::create('/');
        $request->setSession($session);
        $requestStack->push($request);

        try {
            $container->get('security.token_storage')->setToken(
                new UsernamePasswordToken($memberUser, 'main', $memberUser->getRoles()),
            );

            $checker = $container->get('security.authorization_checker');
            self::assertTrue($checker->isGranted(ModuleAccess::FINANCE_READ));
            self::assertFalse($checker->isGranted(ModuleAccess::FINANCE_WRITE));
            self::assertFalse($checker->isGranted(ModuleAccess::MARKETPLACE_READ));
        } finally {
            $container->get('security.token_storage')->setToken(null);
            $requestStack->pop();
        }
    }

    public function testReadOnlyFinanceRoleCanOpenFinancePage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedCompanyWithReadOnlyFinanceMember();

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        // Read-гейт на страницу финансов пропускает read-only шаблон.
        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();
    }

    public function testRootRedirectsOwnerToFinanceDashboard(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $owner] = $this->seedCompanyWithOwner();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/');
        self::assertResponseRedirects('/finance');
    }

    public function testRootRedirectsMarketplaceOnlyMemberToMarketplace(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        [$company] = $this->seedCompanyWithOwner();

        // Системный шаблон «Менеджер маркетплейсов»: marketplace write + catalog read.
        $marketplaceRole = $this->em()->find(CompanyRole::class, SystemCompanyRoles::MARKETPLACE_ID);
        self::assertInstanceOf(CompanyRole::class, $marketplaceRole);

        $memberUser = $this->seedMember($company, CompanyMember::ROLE_OPERATOR, $marketplaceRole);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/');

        // Лендинг обязан увести на доступный модуль, а не отдать 403.
        self::assertResponseRedirects('/marketplace');
    }

    public function testDisabledMemberIsDeniedEvenWithAccessRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company] = $this->seedCompanyWithOwner();
        $memberUser = $this->seedMember($company, CompanyMember::ROLE_OPERATOR, null, CompanyMember::STATUS_DISABLED);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLoginPageStaysPublic(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function seedCompanyWithOwner(): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('module-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($owner)
            ->withName('Module Access Company')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        return [$company, $owner];
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function seedCompanyWithReadOnlyFinanceMember(): array
    {
        [$company] = $this->seedCompanyWithOwner();

        $readOnlyRole = new CompanyRole(
            '66666666-6666-4666-8666-666666666666',
            'Только чтение финансов',
            [Module::FINANCE->value => AccessLevel::READ->value],
        );
        $this->em()->persist($readOnlyRole);

        $memberUser = $this->seedMember($company, CompanyMember::ROLE_OPERATOR, $readOnlyRole);

        return [$company, $memberUser];
    }

    private function seedMember(
        Company $company,
        string $role,
        ?CompanyRole $accessRole,
        string $status = CompanyMember::STATUS_ACTIVE,
    ): User {
        $memberUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail('module-member@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole($role)
            ->withStatus($status)
            ->withAccessRole($accessRole)
            ->build();

        $em = $this->em();
        $em->persist($memberUser);
        $em->persist($member);
        $em->flush();

        return $memberUser;
    }
}
