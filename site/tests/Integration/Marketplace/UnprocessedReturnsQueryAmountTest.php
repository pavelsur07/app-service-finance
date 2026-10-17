<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedReturnsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Суммы возвратов по источнику суммы правила — то, что уходит в документ ОПиУ.
 * Фиксирует формулы до и после выноса их в MarketplacePlAmountSql.
 */
final class UnprocessedReturnsQueryAmountTest extends IntegrationTestCase
{
    private const PERIOD_FROM = '2026-01-01';
    private const PERIOD_TO = '2026-01-31';

    private Company $company;
    private PLCategory $plCategory;
    private MarketplaceListing $listing;
    private UnprocessedReturnsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000301')
            ->withEmail('returns-amount@example.test')
            ->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000301')
            ->withOwner($owner)
            ->build();
        $this->plCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($this->company)
            ->withName('Возвраты')
            ->build();
        $this->listing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->plCategory);
        $this->em->persist($this->listing);
        $this->em->flush();

        $this->query = self::getContainer()->get(UnprocessedReturnsQuery::class);
    }

    public function testRefundTakesRefundAmount(): void
    {
        $this->createMapping(AmountSource::RETURN_REFUND);
        $this->createReturn(quantity: 2, refundAmount: '700.00', costPrice: '100.00', sale: $this->createSale('400.00'));
        $this->em->flush();

        self::assertSame(700.0, $this->total());
    }

    public function testGrossTakesSalePriceTimesReturnedQuantity(): void
    {
        $this->createMapping(AmountSource::RETURN_GROSS);
        $this->createReturn(quantity: 2, refundAmount: '700.00', costPrice: null, sale: $this->createSale('400.00'));
        $this->em->flush();

        self::assertSame(800.0, $this->total());
    }

    public function testGrossFallsBackToRefundWithoutLinkedSale(): void
    {
        $this->createMapping(AmountSource::RETURN_GROSS);
        $this->createReturn(quantity: 2, refundAmount: '650.00', costPrice: null, sale: null);
        $this->em->flush();

        self::assertSame(650.0, $this->total());
    }

    public function testCostPriceTakesReturnCostTimesQuantity(): void
    {
        $this->createMapping(AmountSource::RETURN_COST_PRICE);
        $this->createReturn(quantity: 3, refundAmount: '900.00', costPrice: '120.00', sale: null);
        $this->createReturn(quantity: 1, refundAmount: '300.00', costPrice: null, sale: null);
        $this->em->flush();

        self::assertSame(360.0, $this->total());
    }

    private function total(): float
    {
        $total = 0.0;
        foreach ($this->query->execute((string) $this->company->getId(), 'wildberries', self::PERIOD_FROM, self::PERIOD_TO) as $row) {
            $total += (float) $row['total_amount'];
        }

        return $total;
    }

    private function createMapping(AmountSource $amountSource): void
    {
        $this->em->persist(new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            MarketplaceType::WILDBERRIES,
            $amountSource,
            $this->plCategory,
        ));
    }

    private function createSale(string $pricePerUnit): MarketplaceSale
    {
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($this->company)
            ->forListing($this->listing)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withExternalOrderId('ord-'.Uuid::uuid4()->toString())
            ->withSaleDate(new \DateTimeImmutable('2026-01-05'))
            ->withQuantity(5)
            ->withPricePerUnit($pricePerUnit)
            ->withTotalRevenue('2000.00')
            ->build();
        $this->em->persist($sale);

        return $sale;
    }

    private function createReturn(int $quantity, string $refundAmount, ?string $costPrice, ?MarketplaceSale $sale): void
    {
        $return = new MarketplaceReturn(Uuid::uuid4()->toString(), $this->company, $this->listing, MarketplaceType::WILDBERRIES);
        $return->setReturnDate(new \DateTimeImmutable('2026-01-20'));
        $return->setQuantity($quantity);
        $return->setRefundAmount($refundAmount);
        $return->setCostPrice($costPrice);
        $return->setSale($sale);
        $this->em->persist($return);
    }
}
