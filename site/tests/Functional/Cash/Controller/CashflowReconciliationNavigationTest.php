<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CashflowReconciliationNavigationTest extends WebTestCaseBase
{
    use IntragroupReportFixture;

    private EntityManagerInterface $em;

    public function testSelectionAndDrilldownStayInsideTheAuthorizedReport(): void
    {
        $client = static::createClient();
        $this->em = $this->em();
        $this->createGroupFixture();
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $owner = $this->companies['Альфа']->getUser();
        self::assertNotNull($owner);
        $this->em->clear();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $this->companies['Бета']->getId());
        $url = '/finance/reports/cashflow/group/reconciliation?from=2026-11-01&to=2026-11-18';
        $client->request('GET', $url.'&row=OP_IN_OTHER');
        self::assertResponseIsSuccessful();
        $client->request('GET', $url.'&row=nonexistent');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url.'&operation='.Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url.'&match='.self::transactionId(1));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url.'&operation='.self::transactionId(22));
        self::assertResponseIsSuccessful();
    }
}
