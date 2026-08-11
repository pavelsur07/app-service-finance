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
 *
 * Проверки ограничены контейнером `#sidebar-menu`: слово «Маркетплейсы» встречается и в
 * заголовке страницы, поэтому поиск по всему HTML давал бы ложный green.
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

        $crawler = $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $sidebar = $this->sidebarText($crawler);

        self::assertStringContainsString('Деньги', $sidebar);
        self::assertStringContainsString('Отчёты', $sidebar);
        // Финансовые raw-отчёты доступны этому участнику, значит должны быть и в меню.
        self::assertStringContainsString('Отладка', $sidebar);
        self::assertStringNotContainsString('Маркетплейсы', $sidebar);
        self::assertStringNotContainsString('Загрузка данных', $sidebar);
        self::assertStringNotContainsString('Маркетплейс интеграций', $sidebar);
        self::assertStringNotContainsString('Каталог / Товары', $sidebar);
        self::assertStringNotContainsString('Компания и доступы', $sidebar);
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

        $crawler = $client->request('GET', self::MARKETPLACE_URL);
        self::assertResponseIsSuccessful();

        $sidebar = $this->sidebarText($crawler);

        self::assertStringContainsString('Маркетплейсы', $sidebar);
        self::assertStringContainsString('Загрузка данных', $sidebar);
        self::assertStringNotContainsString('Доходы и расходы', $sidebar);
        self::assertStringNotContainsString('Категории ДДС', $sidebar);
        self::assertStringNotContainsString('Журнал импорта', $sidebar);
        self::assertStringNotContainsString('Отладка', $sidebar);
        self::assertStringNotContainsString('Компания и доступы', $sidebar);
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

        $crawler = $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $sidebar = $this->sidebarText($crawler);

        // Общий блок «Справочники» показывает и финансовые пункты, и пункт каталога.
        self::assertStringContainsString('Категории ДДС', $sidebar);
        self::assertStringContainsString('Каталог / Товары', $sidebar);

        // Ссылка на каталог реально присутствует, а не только текст.
        self::assertGreaterThan(
            0,
            $crawler->filter('#sidebar-menu a[href="/catalog/products"]')->count(),
            'В сайдбаре нет ссылки на каталог товаров.',
        );
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

        $crawler = $client->request('GET', self::FINANCE_URL);
        self::assertResponseIsSuccessful();

        $sidebar = $this->sidebarText($crawler);

        // Владелец компании получает write на все модули, поэтому меню полное.
        $sections = ['Деньги', 'Доходы и расходы', 'Отчёты', 'Отладка', 'Маркетплейсы',
            'Загрузка данных', 'Каталог / Товары', 'Компания и доступы'];
        foreach ($sections as $section) {
            self::assertStringContainsString($section, $sidebar, sprintf('Владелец не видит раздел «%s»', $section));
        }
    }

    private function sidebarText(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $sidebar = $crawler->filter('#sidebar-menu');
        self::assertGreaterThan(0, $sidebar->count(), 'Контейнер сайдбара #sidebar-menu не найден.');

        return $sidebar->html();
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
