<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\Response;

final class MonthPreliminaryRebuildControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-b00000000001';
    private const OWNER_ID   = '22222222-2222-2222-2222-b00000000001';

    public function testHappyPathQueuesMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedActiveSession($client);

        $client->request(
            'POST',
            '/marketplace/month-close/preliminary/rebuild',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['marketplace' => 'ozon', 'year' => 2026, 'month' => 4]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['queued' => true], $data);
    }

    public function testRateLimitedOnSecondCallWithinMinute(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedActiveSession($client);

        // первый запрос — должен пройти
        $client->request(
            'POST',
            '/marketplace/month-close/preliminary/rebuild',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['marketplace' => 'ozon', 'year' => 2026, 'month' => 4]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        // второй запрос с теми же параметрами — должен быть отклонён
        $client->request(
            'POST',
            '/marketplace/month-close/preliminary/rebuild',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['marketplace' => 'ozon', 'year' => 2026, 'month' => 4]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testRejectsInvalidMarketplace(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedActiveSession($client);

        $client->request(
            'POST',
            '/marketplace/month-close/preliminary/rebuild',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['marketplace' => 'unknown', 'year' => 2026, 'month' => 4]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRejectsInvalidPeriod(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedActiveSession($client);

        $client->request(
            'POST',
            '/marketplace/month-close/preliminary/rebuild',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['marketplace' => 'ozon', 'year' => 2026, 'month' => 13]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    private function seedActiveSession(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('preliminary-rebuild-owner@example.test')
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
    }
}
