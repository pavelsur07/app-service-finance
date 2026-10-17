<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Facade;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAds\Facade\PlPreviewAdSpendProvider;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentLineBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class PlPreviewAdSpendProviderTest extends IntegrationTestCase
{
    private const COMPANY = '11111111-1111-1111-1111-000000000501';
    private const FOREIGN = '11111111-1111-1111-1111-000000000502';

    public function testSpendByListingTotalAndUnallocated(): void
    {
        $companies = [];
        foreach ([self::COMPANY, self::FOREIGN] as $i => $companyId) {
            $owner = UserBuilder::aUser()->withId(sprintf('22222222-2222-2222-2222-%012d', 501 + $i))->withEmail("ads-provider-$i@example.test")->build();
            $this->em->persist($owner);
            $companies[$i] = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
            $this->em->persist($companies[$i]);
        }
        $company = $companies[0];
        $a = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withIndex(1)->withMarketplaceSku('ads-1')->build();
        $b = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withIndex(2)->withMarketplaceSku('ads-2')->build();
        $this->em->persist($a);
        $this->em->persist($b);
        $raw = AdRawDocumentBuilder::aRawDocument()->withCompanyId(self::COMPANY)->withMarketplace(MarketplaceType::OZON)->build();
        $this->em->persist($raw);

        // Январь, Ozon: кампания 1 — по двум листингам; кампания 2 — без строк (не привязана).
        $c1 = $this->document(1, self::COMPANY, MarketplaceType::OZON, '2026-01-10', '300.00', $raw->getId());
        $this->line(1, $c1, $a->getId(), '200.00');
        $this->line(2, $c1, $b->getId(), '100.00');
        $this->line(3, $this->document(2, self::COMPANY, MarketplaceType::OZON, '2026-01-31', '150.00', $raw->getId()), $a->getId(), '150.00');
        $this->document(3, self::COMPANY, MarketplaceType::OZON, '2026-01-20', '40.00', $raw->getId());
        // Вне выборки: февраль, WB, чужая компания.
        $this->document(4, self::COMPANY, MarketplaceType::OZON, '2026-02-01', '999.00', $raw->getId());
        $this->document(5, self::COMPANY, MarketplaceType::WILDBERRIES, '2026-01-10', '777.00', $raw->getId());
        $this->document(6, self::FOREIGN, MarketplaceType::OZON, '2026-01-10', '555.00', $raw->getId());
        $this->em->flush();

        $spend = (new PlPreviewAdSpendProvider(self::getContainer()->get(MarketplaceAdsFacade::class)))->spendForPeriod(
            self::COMPANY,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        $byListing = array_map('floatval', $spend->byListing);
        ksort($byListing);
        $expected = [$a->getId() => 350.0, $b->getId() => 100.0];
        ksort($expected);
        self::assertSame($expected, $byListing);
        self::assertSame(490.0, (float) $spend->total);
        self::assertSame('40.00', $spend->unallocated());
    }

    private function document(int $index, string $companyId, MarketplaceType $marketplace, string $date, string $cost, string $rawId): AdDocument
    {
        $document = AdDocumentBuilder::aDocument()
            ->withIndex(500 + $index)
            ->withCompanyId($companyId)
            ->withMarketplace($marketplace)
            ->withReportDate(new \DateTimeImmutable($date))
            ->withTotalCost($cost)
            ->withAdRawDocumentId($rawId)
            ->build();
        $this->em->persist($document);

        return $document;
    }

    private function line(int $index, AdDocument $document, string $listingId, string $cost): void
    {
        $this->em->persist(AdDocumentLineBuilder::aLine()->withIndex(500 + $index)->withAdDocument($document)->withListingId($listingId)->withCost($cost)->build());
    }
}
