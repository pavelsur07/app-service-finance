<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Application\RecalculateSalesDocumentsCostPriceAction;
use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RecalculateSalesDocumentsCostPriceActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000c1';

    public function testRecalculatesOnlySelectedListingsAndCurrentPeriodUsingCostHistory(): void
    {
        $owner = UserBuilder::aUser()
            ->withEmail('listing-cost-recalculation@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $selectedListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('selected-listing')
            ->build();
        $otherListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('other-listing')
            ->build();

        $oldCost = new MarketplaceInventoryCostPrice(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $selectedListing,
            new \DateTimeImmutable('2026-04-01'),
            '100.00',
        );
        $oldCost->closeAt(new \DateTimeImmutable('2026-04-15'));
        $newCost = new MarketplaceInventoryCostPrice(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $selectedListing,
            new \DateTimeImmutable('2026-04-16'),
            '150.00',
        );

        $saleBeforeChange = MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($selectedListing)
            ->withExternalOrderId('selected-before-change')
            ->withSaleDate(new \DateTimeImmutable('2026-04-10'))
            ->withCostPrice('999.00')
            ->build();
        $saleAfterChange = MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($selectedListing)
            ->withExternalOrderId('selected-after-change')
            ->withSaleDate(new \DateTimeImmutable('2026-04-20'))
            ->withCostPrice('999.00')
            ->build();
        $previousMonthSale = MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($selectedListing)
            ->withExternalOrderId('selected-previous-month')
            ->withSaleDate(new \DateTimeImmutable('2026-03-10'))
            ->withCostPrice('999.00')
            ->build();
        $otherListingSale = MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($otherListing)
            ->withExternalOrderId('other-listing-sale')
            ->withSaleDate(new \DateTimeImmutable('2026-04-20'))
            ->withCostPrice('999.00')
            ->build();

        $linkedReturn = (new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $company,
            $selectedListing,
            MarketplaceType::OZON,
        ))
            ->setExternalReturnId('linked-return')
            ->setReturnDate(new \DateTimeImmutable('2026-04-21'))
            ->setQuantity(1)
            ->setRefundAmount('1000.00')
            ->setSale($previousMonthSale)
            ->setCostPrice('999.00');
        $rawOrderDateReturn = (new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $company,
            $selectedListing,
            MarketplaceType::OZON,
        ))
            ->setExternalReturnId('raw-date-return')
            ->setReturnDate(new \DateTimeImmutable('2026-04-22'))
            ->setQuantity(1)
            ->setRefundAmount('1000.00')
            ->setRawData(['order_dt' => '2026-04-20'])
            ->setCostPrice('999.00');
        $otherListingReturn = (new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $company,
            $otherListing,
            MarketplaceType::OZON,
        ))
            ->setExternalReturnId('other-listing-return')
            ->setReturnDate(new \DateTimeImmutable('2026-04-22'))
            ->setQuantity(1)
            ->setRefundAmount('1000.00')
            ->setCostPrice('999.00');

        foreach ([
            $owner,
            $company,
            $selectedListing,
            $otherListing,
            $oldCost,
            $newCost,
            $saleBeforeChange,
            $saleAfterChange,
            $previousMonthSale,
            $otherListingSale,
            $linkedReturn,
            $rawOrderDateReturn,
            $otherListingReturn,
        ] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        /** @var RecalculateSalesDocumentsCostPriceAction $action */
        $action = self::getContainer()->get(RecalculateSalesDocumentsCostPriceAction::class);
        $result = ($action)(new RecalculateSalesCostPriceCommand(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-30'),
            listingIds: [$selectedListing->getId()],
        ));

        self::assertSame(['sales' => 2, 'returns' => 2], $result);
        self::assertSame('100.00', $saleBeforeChange->getCostPrice());
        self::assertSame('150.00', $saleAfterChange->getCostPrice());
        self::assertSame('999.00', $previousMonthSale->getCostPrice());
        self::assertSame('999.00', $otherListingSale->getCostPrice());
        self::assertSame('100.00', $linkedReturn->getCostPrice());
        self::assertSame('150.00', $rawOrderDateReturn->getCostPrice());
        self::assertSame('999.00', $otherListingReturn->getCostPrice());
    }
}
