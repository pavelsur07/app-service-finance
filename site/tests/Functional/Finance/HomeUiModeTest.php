<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Shared\Service\UiModeResolver;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

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
        self::assertSelectorCount(4, '[data-dashboard-kpi-comparison]');
    }

    public function testUserCookieRendersAppDashboardWithoutTablerAssets(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);
        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="app"]');
        self::assertSelectorExists('.app-customer [data-dashboard-mode="app"]');
        self::assertSelectorTextContains('.app-header .crumb-current', 'Финансовый обзор');
        self::assertSelectorTextContains('.app-header .app-company', 'Тестовая компания');
        self::assertSelectorExists('.app-company[title="Тестовая компания"]');
        self::assertSelectorTextContains('.app-company .sr-only', 'Активная компания:');
        self::assertSelectorExists('.app-sidebar .sb-item[aria-current="page"]');
        self::assertSelectorTextContains('.app-dashboard', 'Остаток на сегодня');
        self::assertSelectorTextContains('.ui-mode-switch button[aria-pressed="true"]', 'Новый');
        self::assertStringNotContainsString('@tabler/core', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('#react-dashboard-started');
    }

    public function testAdminCookieStillRendersAppDashboard(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, true);
        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));

        $client->request('GET', self::DASHBOARD_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="app"]');
        self::assertSelectorExists('[data-dashboard-mode="app"]');
    }

    public function testSwitchChangesDashboardModeWithoutChangingUrl(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, false);
        $crawler = $client->request('GET', self::DASHBOARD_URL);
        $client->setServerParameter('HTTP_REFERER', 'http://localhost'.self::DASHBOARD_URL);

        $client->submit($crawler->selectButton('Новый')->form());

        self::assertResponseRedirects(self::DASHBOARD_URL, Response::HTTP_SEE_OTHER);
        $crawler = $client->followRedirect();
        self::assertSelectorExists('html[data-ui-mode="app"]');

        $client->submit($crawler->selectButton('Старый')->form());

        self::assertResponseRedirects(self::DASHBOARD_URL, Response::HTTP_SEE_OTHER);
        $client->followRedirect();
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
        $this->resetDb();
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
