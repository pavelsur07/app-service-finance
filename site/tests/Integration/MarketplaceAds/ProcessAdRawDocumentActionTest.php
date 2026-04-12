<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ProcessAdRawDocumentActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID   = '22222222-2222-2222-2222-000000000001';

    public function testProcessesOzonRawDocumentAndCreatesAdDocuments(): void
    {
        $company = $this->seedCompany();
        $this->seedListing($company, 'SKU-PARENT-1', 'L', '55555555-5555-5555-5555-000000000001');
        $this->seedListing($company, 'SKU-PARENT-1', 'XL', '55555555-5555-5555-5555-000000000002');
        $this->em->flush();

        $rawDocumentId = $this->seedOzonRawDocument([
            ['campaign_id' => 'CAMP-A', 'campaign_name' => 'Осенняя', 'sku' => 'SKU-PARENT-1',
             'spend' => 100.00, 'views' => 1000, 'clicks' => 40],
        ]);

        ($this->action())(self::COMPANY_ID, $rawDocumentId);

        $this->em->clear();

        $rawDocument = $this->em->getRepository(AdRawDocument::class)->find($rawDocumentId);
        self::assertInstanceOf(AdRawDocument::class, $rawDocument);
        self::assertSame(AdRawDocumentStatus::PROCESSED, $rawDocument->getStatus());

        $adDocuments = $this->em->getRepository(AdDocument::class)->findBy(['adRawDocumentId' => $rawDocumentId]);
        self::assertCount(1, $adDocuments);

        $adDocument = $adDocuments[0];
        self::assertSame('CAMP-A', $adDocument->getCampaignId());
        self::assertSame('Осенняя', $adDocument->getCampaignName());
        self::assertSame('SKU-PARENT-1', $adDocument->getParentSku());
        self::assertSame('100.00', $adDocument->getTotalCost());
        self::assertSame(1000, $adDocument->getTotalImpressions());
        self::assertSame(40, $adDocument->getTotalClicks());

        /** @var AdDocumentLine[] $lines */
        $lines = $this->em->getRepository(AdDocumentLine::class)->findBy(['adDocument' => $adDocument->getId()]);
        self::assertCount(2, $lines);

        // Продаж нет → равномерное распределение 50/50 + поправка округления → 100.00 суммарно.
        $sumCost = '0.00';
        foreach ($lines as $line) {
            $sumCost = bcadd($sumCost, $line->getCost(), 2);
        }
        self::assertSame('100.00', $sumCost);
    }

    public function testPartialProcessingLeavesDocumentInDraftWhenSomeSkusNotFound(): void
    {
        $company = $this->seedCompany();
        $this->seedListing($company, 'SKU-KNOWN', 'UNKNOWN', '55555555-5555-5555-5555-000000000010');
        $this->em->flush();

        $rawDocumentId = $this->seedOzonRawDocument([
            ['campaign_id' => 'CAMP-1', 'campaign_name' => 'Кампания 1', 'sku' => 'SKU-KNOWN',
             'spend' => 50.00, 'views' => 500, 'clicks' => 25],
            ['campaign_id' => 'CAMP-2', 'campaign_name' => 'Кампания 2', 'sku' => 'SKU-MISSING',
             'spend' => 80.00, 'views' => 800, 'clicks' => 30],
        ]);

        ($this->action())(self::COMPANY_ID, $rawDocumentId);

        $this->em->clear();

        $rawDocument = $this->em->getRepository(AdRawDocument::class)->find($rawDocumentId);
        self::assertSame(
            AdRawDocumentStatus::DRAFT,
            $rawDocument->getStatus(),
            'Документ должен остаться в DRAFT при частичной обработке',
        );

        $adDocuments = $this->em->getRepository(AdDocument::class)->findBy(['adRawDocumentId' => $rawDocumentId]);
        self::assertCount(1, $adDocuments, 'Только известный SKU должен быть обработан');
        self::assertSame('SKU-KNOWN', $adDocuments[0]->getParentSku());
        self::assertSame('CAMP-1', $adDocuments[0]->getCampaignId());
    }

    public function testIdempotentReprocessDeletesPreviousAdDocuments(): void
    {
        $company = $this->seedCompany();
        $this->seedListing($company, 'SKU-IDEM', 'UNKNOWN', '55555555-5555-5555-5555-000000000020');
        $this->em->flush();

        $rawDocumentId = $this->seedOzonRawDocument([
            ['campaign_id' => 'CAMP-X', 'campaign_name' => 'X', 'sku' => 'SKU-IDEM',
             'spend' => 10.00, 'views' => 100, 'clicks' => 5],
        ]);

        $action = $this->action();
        $action(self::COMPANY_ID, $rawDocumentId);
        $this->em->clear();

        // Сброс в draft и повторная обработка.
        /** @var AdRawDocument $rawDocument */
        $rawDocument = $this->em->getRepository(AdRawDocument::class)->find($rawDocumentId);
        $rawDocument->resetToDraft();
        $this->em->flush();
        $this->em->clear();

        $action(self::COMPANY_ID, $rawDocumentId);
        $this->em->clear();

        $adDocuments = $this->em->getRepository(AdDocument::class)->findBy(['adRawDocumentId' => $rawDocumentId]);
        self::assertCount(1, $adDocuments, 'Повторная обработка не должна дублировать AdDocument');
    }

    public function testThrowsWhenDocumentIsNotDraft(): void
    {
        $company = $this->seedCompany();
        $this->seedListing($company, 'SKU-Y', 'UNKNOWN', '55555555-5555-5555-5555-000000000030');
        $this->em->flush();

        $rawDocumentId = $this->seedOzonRawDocument([
            ['campaign_id' => 'C', 'campaign_name' => 'N', 'sku' => 'SKU-Y',
             'spend' => 1.00, 'views' => 1, 'clicks' => 0],
        ]);

        $action = $this->action();
        $action(self::COMPANY_ID, $rawDocumentId);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ожидался draft');

        $action(self::COMPANY_ID, $rawDocumentId);
    }

    public function testThrowsWhenDocumentNotFound(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не найден');

        ($this->action())(self::COMPANY_ID, '99999999-9999-9999-9999-999999999999');
    }

    private function action(): ProcessAdRawDocumentAction
    {
        return self::getContainer()->get(ProcessAdRawDocumentAction::class);
    }

    private function seedCompany(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedListing(Company $company, string $parentSku, string $size, string $listingId): MarketplaceListing
    {
        $listing = new MarketplaceListing($listingId, $company, null, MarketplaceType::OZON);
        $listing->setMarketplaceSku($parentSku);
        $listing->setSize($size);
        $listing->setPrice('0.00');

        $this->em->persist($listing);

        return $listing;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function seedOzonRawDocument(array $rows): string
    {
        $payload = json_encode(['rows' => $rows], JSON_THROW_ON_ERROR);

        $rawDocument = new AdRawDocument(
            companyId:   self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  new \DateTimeImmutable('2026-04-10'),
            rawPayload:  $payload,
        );

        $this->em->persist($rawDocument);
        $this->em->flush();

        return $rawDocument->getId();
    }
}
