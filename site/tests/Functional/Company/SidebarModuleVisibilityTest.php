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
use Ramsey\Uuid\Uuid;

/**
 * Меню скрывает разделы недоступных модулей.
 *
 * До Stage 5 участник с ограниченным шаблоном видел полный сайдбар и получал 403 по клику:
 * гейты работали, а навигация врала. Здесь проверяется, что пункт исчезает, а не просто
 * перестаёт открываться.
 */
final class SidebarModuleVisibilityTest extends WebTestCaseBase
{
    private const FINANCE_URL = '/finance/cash-transactions/';
    private const MARKETPLACE_URL = '/marketplace';

    public function testFinanceOnlyMemberDoesNotSeeMarketplaceSections(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMember('finance', [Module::FINANCE->value => AccessLevel::READ->value], 1);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Деньги', $html);
        self::assertStringContainsString('Отчёты', $html);
        self::assertStringNotContainsString('Маркетплейсы', $html);
        self::assertStringNotContainsString('Загрузка данных', $html);
        self::assertStringNotContainsString('Маркетплейс интеграций', $html);
        self::assertStringNotContainsString('Каталог / Товары', $html);
        self::assertStringNotContainsString('Компания и доступы', $html);
    }

    public function testMarketplaceOnlyMemberDoesNotSeeFinanceSections(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMember(
            'marketplace',
            [Module::MARKETPLACE->value => AccessLevel::READ->value],
            2,
        );

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::MARKETPLACE_URL);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Маркетплейсы', $html);
        self::assertStringNotContainsString('Доходы и расходы', $html);
        self::assertStringNotContainsString('Категории ДДС', $html);
        self::assertStringNotContainsString('Журнал импорта', $html);
        self::assertStringNotContainsString('Компания и доступы', $html);
    }

    public function testCatalogItemIsVisibleOnlyWithCatalogAccess(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMember(
            'finance-catalog',
            [
                Module::FINANCE->value => AccessLevel::READ->value,
                Module::CATALOG->value => AccessLevel::READ->value,
            ],
            3,
        );

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        // Общий блок «Справочники» и финансовые пункты, и пункт каталога.
        self::assertStringContainsString('Категории ДДС', $html);
        self::assertStringContainsString('Каталог / Товары', $html);
    }

    public function testOwnerSeesEverySection(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withIndex(40)
            ->withEmail('sidebar-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()->withIndex(40)->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        // Владелец компании получает write на все модули, поэтому меню полное.
        foreach (['Деньги', 'Доходы и расходы', 'Отчёты', 'Маркетплейсы', 'Каталог / Товары', 'Компания и доступы'] as $section) {
            self::assertStringContainsString($section, $html, sprintf('Владелец не видит раздел «%s»', $section));
        }
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array{0: Company, 1: User}
     */
    private function seedMember(string $slug, array $permissions, int $index): array
    {
        $owner = UserBuilder::aUser()
            ->withIndex(50 + $index)
            ->withEmail(sprintf('sidebar-owner-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex(50 + $index)
            ->withOwner($owner)
            ->withName('Sidebar Company '.$slug)
            ->build();

        $role = new CompanyRole(Uuid::uuid4()->toString(), 'Шаблон '.$slug, $permissions, $company);

        $memberUser = UserBuilder::aUser()
            ->withIndex(60 + $index)
            ->withEmail(sprintf('sidebar-member-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withId(Uuid::uuid4()->toString())
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
