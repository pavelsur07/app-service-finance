<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class StockReportControllerTest extends WebTestCaseBase
{
    public function testReportOpensAndShowsDefaultLatestSnapshotForOzon(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-stock-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112001')->withOwner($owner)->build();

        $older = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId('22222222-2222-4222-8222-222222222001')
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442001')
            ->withSnapshotAt(new \DateTimeImmutable('2026-05-10T09:00:00+00:00'))
            ->withSourceSku('SKU-OLD')
            ->build();

        $latest = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId('22222222-2222-4222-8222-222222222002')
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442002')
            ->withSnapshotAt(new \DateTimeImmutable('2026-05-11T09:00:00+00:00'))
            ->withSourceSku('SKU-LATEST')
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($older);
        $em->persist($latest);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', '/inventory/stocks');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SKU-LATEST', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('SKU-OLD', (string) $client->getResponse()->getContent());
    }


    public function testInvalidSnapshotSessionIdDoesNotBreakPageAndFallsBackToLatest(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-stock-invalid-session@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112003')->withOwner($owner)->build();

        $older = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId('22222222-2222-4222-8222-222222222101')
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442101')
            ->withSnapshotAt(new \DateTimeImmutable('2026-05-10T09:00:00+00:00'))
            ->withSourceSku('SKU-OLD-INVALID')
            ->build();

        $latest = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId('22222222-2222-4222-8222-222222222102')
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442102')
            ->withSnapshotAt(new \DateTimeImmutable('2026-05-11T09:00:00+00:00'))
            ->withSourceSku('SKU-LATEST-INVALID')
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($older);
        $em->persist($latest);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', '/inventory/stocks?snapshotSessionId=not-a-uuid');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-LATEST-INVALID', $html);
        self::assertStringNotContainsString('SKU-OLD-INVALID', $html);
    }

    public function testMappingStatusFilterAndAvailableForSaleAndUnmappedVisible(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-stock-filter@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112002')->withOwner($owner)->build();

        $sessionId = '22222222-2222-4222-8222-222222222010';

        $unmapped = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId($sessionId)
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442010')
            ->withSourceSku('UNMAPPED-SKU')
            ->withQuantity('10.000')
            ->withReservedQuantity('3.000')
            ->withMappingStatus(StockSnapshotMappingStatus::Unmapped)
            ->build();

        $mapped = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSource(MarketplaceType::OZON)
            ->withSnapshotSessionId($sessionId)
            ->withRawSnapshotId('44444444-4444-4444-8444-444444442011')
            ->withSourceSku('MAPPED-SKU')
            ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($unmapped);
        $em->persist($mapped);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/inventory/stocks?mappingStatus=unmapped&snapshotSessionId='.$sessionId);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('UNMAPPED-SKU', $html);
        self::assertStringNotContainsString('MAPPED-SKU', $html);
        self::assertStringContainsString('10.000', $html);
        self::assertStringContainsString('3.000', $html);
        self::assertStringContainsString('7.000', $html);

        $client->request('GET', '/inventory/snapshots');
        self::assertResponseIsSuccessful();
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
