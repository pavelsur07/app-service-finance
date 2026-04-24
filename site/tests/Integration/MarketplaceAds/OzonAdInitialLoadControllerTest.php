<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class OzonAdInitialLoadControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-a00000000001';
    private const OWNER_ID   = '22222222-2222-2222-2222-a00000000001';

    // После Task-11.9a happy-path для initial-load контроллера проверяется
    // не здесь: период Jan 1 → yesterday в календарное большинство года
    // превышает 62-дневный лимит Ozon и сразу отбрасывается validator'ом
    // DispatchOzonAdLoadAction. Функциональное покрытие нового pipeline'а —
    // в `OzonAdLoadRangeControllerTest` (короткий период + mocked Planner).

    public function testReturns400WhenNoPerformanceConnection(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ozon-ads-no-conn@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('POST', '/api/marketplace-ads/ozon/initial-load', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $data);
        self::assertStringContainsString('Ozon Performance connection not configured', $data['message']);
    }
}
