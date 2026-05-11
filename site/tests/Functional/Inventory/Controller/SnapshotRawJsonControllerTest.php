<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class SnapshotRawJsonControllerTest extends WebTestCaseBase
{
    public function testOwnCompanySessionWithRawSnapshotsReturns200AndPayload(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-json-own@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111801')->withOwner($owner)->build();

        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->build();
        $session->markCompleted();

        $raw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($session->getId())
            ->withSource(MarketplaceType::OZON)
            ->withEndpoint('/v4/product/info/stocks')
            ->withResponseBody(['result' => ['items' => [['sku' => 'A1']]]])
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($session);
        $em->persist($raw);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', sprintf('/inventory/snapshots/%s/json', $session->getId()));

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($session->getId(), $json['snapshotSession']['id']);
        self::assertSame('ozon', $json['snapshotSession']['source']);
        self::assertSame(1, count($json['rawSnapshots']));
        self::assertSame('/v4/product/info/stocks', $json['rawSnapshots'][0]['sourceEndpoint']);
        self::assertSame('A1', $json['rawSnapshots'][0]['responseBody']['result']['items'][0]['sku']);
        self::assertArrayNotHasKey('apiKey', $json);
        self::assertArrayNotHasKey('clientSecret', $json);
        self::assertArrayNotHasKey('credentials', $json);
    }

    public function testForeignCompanySessionReturns404(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-json-foreign@example.test')->build();
        $companyA = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111802')->withOwner($owner)->build();
        $companyB = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111803')->withOwner($owner)->build();

        $foreignSession = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId($companyB->getId())
            ->build();
        $foreignSession->markCompleted();

        $em->persist($owner);
        $em->persist($companyA);
        $em->persist($companyB);
        $em->persist($foreignSession);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $companyA);
        $client->request('GET', sprintf('/inventory/snapshots/%s/json', $foreignSession->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNotExistingSessionReturns404(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-json-missing@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111804')->withOwner($owner)->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', '/inventory/snapshots/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/json');

        self::assertResponseStatusCodeSame(404);
    }

    public function testFailedSessionWithoutRawSnapshotsReturns200WithMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-json-empty@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111805')->withOwner($owner)->build();

        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->build();
        $session->markFailed('fetch error');

        $em->persist($owner);
        $em->persist($company);
        $em->persist($session);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', sprintf('/inventory/snapshots/%s/json', $session->getId()));

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($session->getId(), $json['snapshotSession']['id']);
        self::assertSame([], $json['rawSnapshots']);
        self::assertSame('No raw snapshots saved for this session.', $json['message']);
    }

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/inventory/snapshots/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/json');

        self::assertTrue($client->getResponse()->isRedirect() || $client->getResponse()->getStatusCode() === 403);
    }
}
