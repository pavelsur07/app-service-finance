<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\DataFixtures\AppFixtures;
use App\DataFixtures\CashflowCategoryFixtures;
use App\DataFixtures\CashTransactionsFixtures;
use App\DataFixtures\PLCategoryFixtures;
use App\DataFixtures\PLDocumentsFixtures;
use App\DataFixtures\ProjectDirectionsFixtures;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;

final class DashboardSnapshotGoldenTest extends WebTestCaseBase
{
    public function testSnapshotGoldenValuesForCurrentMonthFromA22Fixtures(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->loadA22Fixtures();

        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'owner@app.ru']);
        $company = $em->getRepository(Company::class)->findOneBy(['name' => 'ООО "Ромашка"']);

        self::assertNotNull($user);
        self::assertNotNull($company);

        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $client->request('GET', '/api/dashboard/v1/snapshot?preset=month');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertGreaterThan(0.0, (float) ($payload['widgets']['free_cash']['value'] ?? 0));
        self::assertGreaterThan(0.0, (float) ($payload['widgets']['inflow']['sum'] ?? 0));
        self::assertGreaterThan(0.0, (float) ($payload['widgets']['outflow']['sum_abs'] ?? 0));
        self::assertGreaterThan(0.0, (float) ($payload['widgets']['revenue']['sum'] ?? 0));
        self::assertNotEquals(0.0, (float) ($payload['widgets']['profit']['ebitda'] ?? 0));
        self::assertGreaterThan(0, count($payload['widgets']['top_cash']['items'] ?? []));
        self::assertGreaterThan(0, count($payload['widgets']['top_pnl']['items'] ?? []));
        self::assertNotNull($payload['context']['last_updated_at'] ?? null);

        // Golden values from A22 fixture rules for current month.
        $expectedInflow = 400000.0 + 10000.0;
        $expectedOutflowAbs = 160000.0 + 50000.0;
        $expectedRevenue = 280000.0 + 230000.0;

        self::assertSame($expectedInflow, (float) $payload['widgets']['inflow']['sum']);
        self::assertSame($expectedOutflowAbs, (float) $payload['widgets']['outflow']['sum_abs']);
        self::assertSame($expectedRevenue, (float) $payload['widgets']['revenue']['sum']);
    }

    private function loadA22Fixtures(): void
    {
        $container = static::getContainer();

        $loader = new Loader();
        $loader->addFixture($container->get(AppFixtures::class));
        $loader->addFixture($container->get(ProjectDirectionsFixtures::class));
        $loader->addFixture($container->get(CashflowCategoryFixtures::class));
        $loader->addFixture($container->get(CashTransactionsFixtures::class));
        $loader->addFixture($container->get(PLCategoryFixtures::class));
        $loader->addFixture($container->get(PLDocumentsFixtures::class));

        $executor = new ORMExecutor($this->em(), new ORMPurger());
        $executor->execute($loader->getFixtures(), true);
    }
}
