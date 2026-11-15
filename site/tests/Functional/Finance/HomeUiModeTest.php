<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Shared\Service\UiModeResolver;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

final class HomeUiModeTest extends WebTestCaseBase
{
    /** Финансовый дашборд: «/» отдан роутеру лендинга, «/dashboard» — React-пилоту. */
    private const DASHBOARD_URL = '/finance';

    public function testDashboardUsesLegacyModeByDefault(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorNotExists('#react-dashboard-started');
        self::assertStringContainsString('@tabler/core@1.2.0', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-dashboard-mode="app"]');
        self::assertSelectorNotExists('.ui-mode-switch');
        self::assertSelectorCount(4, '[data-dashboard-kpi-comparison]');
        self::assertSelectorExists(
            '#finance-balance-dynamics-root[data-endpoint="/api/finance/dashboard/balance-dynamics"][data-currency="RUB"]',
        );
    }

    public function testRetiredUserCookieRendersLegacyDashboard(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);
        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorNotExists('[data-dashboard-mode="app"]');
        self::assertStringContainsString('@tabler/core@1.2.0', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('#react-dashboard-started');
        self::assertSelectorExists('#finance-balance-dynamics-root');
    }

    public function testAdminVisitingCustomerDashboardAlsoUsesLegacy(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, true);
        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorNotExists('[data-dashboard-mode="app"]');
    }

    public function testRetiredThemeCookieCannotEnableAppDashboard(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);
        $client->getCookieJar()->set(new Cookie('ui_theme', 'vf'));

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
    }

    public function testNonMigratedRouteRemainsLegacyInAppMode(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);
        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));

        $client->request('GET', '/finance/cash-transactions/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorNotExists('.app-customer');
    }

    private function loginWithCompany(KernelBrowser $client, bool $admin): void
    {
        $builder = UserBuilder::aUser()->withEmail(
            $admin ? 'home-ui-admin@example.test' : 'home-ui-user@example.test',
        );
        if ($admin) {
            $builder = $builder->withRoles(['ROLE_ADMIN']);
        }

        $user = $builder->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Тестовая компания')
            ->build();

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
