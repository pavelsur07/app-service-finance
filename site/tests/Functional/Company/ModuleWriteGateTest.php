<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * Write-гейты Stage 3 по HTTP: read проходит, write без права — 403, отсутствие
 * модуля — 403 уже на чтении.
 *
 * Проверяется сквозной путь, а не голосование voter'а (это делает ModuleAccessTest):
 * важно, что гейт срабатывает раньше обработки формы и CSRF, поэтому POST без тела
 * обязан получить 403, а не 422 и не 500.
 */
final class ModuleWriteGateTest extends WebTestCaseBase
{
    private const FINANCE_READ_URL = '/counterparties/';
    private const FINANCE_WRITE_URL = '/counterparties/new';
    private const DEALS_WRITE_URL = '/deals/new';
    private const CATALOG_WRITE_URL = '/catalog/products/new';

    public function testReadOnlyFinanceRoleReadsButCannotWrite(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions('finance-read', [
            Module::FINANCE->value => AccessLevel::READ->value,
        ]);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_READ_URL);
        self::assertResponseIsSuccessful();

        $client->request('POST', self::FINANCE_WRITE_URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFinanceWriteRoleCanReachWriteEndpoint(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions('finance-write', [
            Module::FINANCE->value => AccessLevel::WRITE->value,
        ]);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        // Гейт пропускает; форма с пустым телом невалидна и рендерится заново — но это 200, не 403.
        $client->request('POST', self::FINANCE_WRITE_URL);
        self::assertResponseIsSuccessful();
    }

    public function testRoleWithoutDealsCannotWriteDeals(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions('finance-only', [
            Module::FINANCE->value => AccessLevel::WRITE->value,
        ]);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        // Модуля deals в шаблоне нет — read-гейт подписчика отказывает раньше write-гейта.
        $client->request('POST', self::DEALS_WRITE_URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyCatalogRoleCannotWriteCatalog(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions('catalog-read', [
            Module::CATALOG->value => AccessLevel::READ->value,
        ]);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', self::CATALOG_WRITE_URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyPermissionsRoleIsDeniedEvenOnRead(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions('no-access', []);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_READ_URL);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array{0: Company, 1: User}
     */
    private function seedMemberWithPermissions(string $slug, array $permissions): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail(sprintf('write-gate-owner-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($owner)
            ->withName('Write Gate Company')
            ->build();

        $role = new CompanyRole(
            '77777777-7777-4777-8777-777777777777',
            'Шаблон '.$slug,
            $permissions,
        );
        $role->setCompany($company);

        $memberUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail(sprintf('write-gate-member-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withStatus(CompanyMember::STATUS_ACTIVE)
            ->withAccessRole($role)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($role);
        $em->persist($memberUser);
        $em->persist($member);
        $em->flush();

        return [$company, $memberUser];
    }
}
