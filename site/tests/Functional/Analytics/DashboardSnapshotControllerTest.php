<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
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
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();

        $client->request('GET', '/api/dashboard/v1/snapshot');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('widgets', $payload);
        self::assertIsArray($payload['widgets']);

        self::assertArrayHasKey('free_cash', $payload['widgets']);
        self::assertArrayHasKey('inflow', $payload['widgets']);
        self::assertArrayHasKey('outflow', $payload['widgets']);
        self::assertArrayHasKey('cashflow_split', $payload['widgets']);
        self::assertArrayHasKey('revenue', $payload['widgets']);
        self::assertArrayHasKey('top_cash', $payload['widgets']);
        self::assertArrayHasKey('top_pnl', $payload['widgets']);
        self::assertArrayHasKey('profit', $payload['widgets']);
        self::assertArrayHasKey('alerts', $payload['widgets']);

        self::assertIsArray($payload['widgets']['revenue']);
        self::assertArrayHasKey('sum', $payload['widgets']['revenue']);
        self::assertArrayHasKey('delta_abs', $payload['widgets']['revenue']);
        self::assertArrayHasKey('delta_pct', $payload['widgets']['revenue']);
    }
}

