<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Analytics\Domain\DrilldownKey;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\SystemCompanyRoles;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Db\SystemCompanyRolesSeeder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class DashboardSnapshotControllerTest extends WebTestCaseBase
{
    public function testSnapshotContainsAllWidgetKeys(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();

        $companyId = $company->getId();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        $client->request('GET', '/api/dashboard/v1/snapshot');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('widgets', $payload);
        self::assertIsArray($payload['widgets']);
        self::assertArrayHasKey('context', $payload);
        self::assertIsArray($payload['context']);
        self::assertArrayHasKey('last_updated_at', $payload['context']);

        self::assertArrayHasKey('free_cash', $payload['widgets']);
        self::assertArrayHasKey('inflow', $payload['widgets']);
        self::assertArrayHasKey('outflow', $payload['widgets']);
        self::assertArrayHasKey('cashflow_split', $payload['widgets']);
        self::assertArrayHasKey('revenue', $payload['widgets']);
        self::assertArrayHasKey('top_cash', $payload['widgets']);
        self::assertArrayHasKey('top_pnl', $payload['widgets']);
        self::assertArrayHasKey('profit', $payload['widgets']);
        self::assertArrayHasKey('alerts', $payload['widgets']);

        self::assertIsArray($payload['widgets']['alerts']);
        self::assertArrayHasKey('items', $payload['widgets']['alerts']);
        self::assertArrayHasKey('warnings', $payload['widgets']);
        self::assertIsArray($payload['widgets']['warnings']);
        self::assertArrayHasKey('items', $payload['widgets']['warnings']);

        self::assertIsArray($payload['widgets']['revenue']);

        self::assertIsArray($payload['widgets']['cashflow_split']);
        self::assertArrayHasKey('operating', $payload['widgets']['cashflow_split']);
        self::assertArrayHasKey('investing', $payload['widgets']['cashflow_split']);
        self::assertArrayHasKey('financing', $payload['widgets']['cashflow_split']);
        self::assertArrayHasKey('total', $payload['widgets']['cashflow_split']);
        self::assertArrayHasKey('net', $payload['widgets']['cashflow_split']['operating']);
        self::assertArrayHasKey('net', $payload['widgets']['cashflow_split']['investing']);
        self::assertArrayHasKey('net', $payload['widgets']['cashflow_split']['financing']);

        self::assertIsArray($payload['widgets']['outflow']);
        self::assertArrayHasKey('sum_abs', $payload['widgets']['outflow']);
        self::assertArrayHasKey('capex_abs', $payload['widgets']['outflow']);
        self::assertArrayHasKey('ratio_to_inflow', $payload['widgets']['outflow']);
        self::assertArrayHasKey('series', $payload['widgets']['outflow']);
        self::assertIsArray($payload['widgets']['outflow']['series']);

        self::assertArrayHasKey('sum', $payload['widgets']['revenue']);
        self::assertArrayHasKey('delta_abs', $payload['widgets']['revenue']);
        self::assertArrayHasKey('delta_pct', $payload['widgets']['revenue']);

        self::assertIsArray($payload['widgets']['top_pnl']);
        self::assertArrayHasKey('coverage_target', $payload['widgets']['top_pnl']);
        self::assertArrayHasKey('max_items', $payload['widgets']['top_pnl']);
        self::assertArrayHasKey('items', $payload['widgets']['top_pnl']);
        self::assertArrayHasKey('other', $payload['widgets']['top_pnl']);

        self::assertIsArray($payload['widgets']['top_cash']);
        self::assertArrayHasKey('items', $payload['widgets']['top_cash']);
        self::assertArrayHasKey('other', $payload['widgets']['top_cash']);
        self::assertIsArray($payload['widgets']['top_cash']['items']);

        self::assertIsArray($payload['widgets']['profit']);
        self::assertArrayHasKey('revenue', $payload['widgets']['profit']);
        self::assertArrayHasKey('variable_costs', $payload['widgets']['profit']);
        self::assertArrayHasKey('opex', $payload['widgets']['profit']);
        self::assertArrayHasKey('ebitda', $payload['widgets']['profit']);
        self::assertArrayHasKey('margin_pct', $payload['widgets']['profit']);
        self::assertArrayHasKey('delta', $payload['widgets']['profit']);

        $allowedDrilldownKeys = [
            DrilldownKey::CASH_TRANSACTIONS,
            DrilldownKey::CASH_BALANCES,
            DrilldownKey::FUNDS_RESERVED,
            DrilldownKey::PL_DOCUMENTS,
            DrilldownKey::PL_REPORT,
        ];

        foreach ($this->collectDrilldownKeys($payload) as $drilldownKey) {
            self::assertTrue(in_array($drilldownKey, $allowedDrilldownKeys, true));
        }
    }

    public function testMarketplaceOnlyMemberDoesNotSeeFinanceWidgets(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $marketplaceRole = $this->em()->find(CompanyRole::class, SystemCompanyRoles::MARKETPLACE_ID);
        self::assertInstanceOf(CompanyRole::class, $marketplaceRole);
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('marketplace@example.test')->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withAccessRole($marketplaceRole)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/api/dashboard/v1/snapshot');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('context', $payload);
        self::assertArrayHasKey('widgets', $payload);
        self::assertArrayNotHasKey('free_cash', $payload['widgets']);
        self::assertArrayNotHasKey('inflow', $payload['widgets']);
        self::assertArrayNotHasKey('revenue', $payload['widgets']);
        self::assertArrayNotHasKey('profit', $payload['widgets']);
        self::assertArrayHasKey('alerts', $payload['widgets']);
        self::assertArrayHasKey('warnings', $payload['widgets']);
    }

    /**
     * @return list<string>
     */
    private function collectDrilldownKeys(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $keys = [];
        if (isset($payload['key']) && is_string($payload['key'])) {
            $keys[] = $payload['key'];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $keys = [...$keys, ...$this->collectDrilldownKeys($value)];
            }
        }

        return $keys;
    }
}
