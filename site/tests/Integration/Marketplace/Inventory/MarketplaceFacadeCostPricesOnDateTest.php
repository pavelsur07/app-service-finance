<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Inventory;

use App\Company\Entity\Company;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MarketplaceFacadeCostPricesOnDateTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000d1';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-0000000000d2';

    public function testBatchFollowsSingleListingResolverRulesWithinCompany(): void
    {
        $company = $this->company(self::COMPANY_ID, 1);
        $otherCompany = $this->company(self::OTHER_COMPANY_ID, 2);

        $changed = $this->listing($company, 'changed');
        $old = $this->cost(self::COMPANY_ID, $changed, '2026-04-01', '100.00');
        $old->closeAt(new \DateTimeImmutable('2026-04-15'));
        $this->cost(self::COMPANY_ID, $changed, '2026-04-16', '150.00');

        $startsLater = $this->listing($company, 'starts-later');
        $this->cost(self::COMPANY_ID, $startsLater, '2026-05-01', '120.00');

        $withoutHistory = $this->listing($company, 'without-history');

        $zeroPrice = $this->listing($company, 'zero-price');
        $this->cost(self::COMPANY_ID, $zeroPrice, '2026-04-01', '0.00');

        $foreign = $this->listing($otherCompany, 'foreign');
        $this->cost(self::OTHER_COMPANY_ID, $foreign, '2026-04-01', '500.00');

        // Запись закрыта, преемника нет: на дату после закрытия резолвер берёт самую раннюю.
        $closed = $this->listing($company, 'closed-without-successor');
        $this->cost(self::COMPANY_ID, $closed, '2026-03-01', '70.00')->closeAt(new \DateTimeImmutable('2026-03-31'));

        $this->em->flush();
        $this->em->clear();

        /** @var MarketplaceFacade $facade */
        $facade = self::getContainer()->get(MarketplaceFacade::class);
        $ids = [$changed->getId(), $startsLater->getId(), $withoutHistory->getId(), $zeroPrice->getId(), $foreign->getId()];

        $endOfMonth = $facade->getCostPricesForListingsOnDate(self::COMPANY_ID, $ids, new \DateTimeImmutable('2026-04-30'));
        $midMonth = $facade->getCostPricesForListingsOnDate(self::COMPANY_ID, $ids, new \DateTimeImmutable('2026-04-10'));

        // Действующая на дату запись; дата раньше первой записи — самая ранняя.
        // Без истории, с нулевой ценой и чужая компания — в результат не попадают.
        self::assertEquals([$changed->getId() => '150.00', $startsLater->getId() => '120.00'], $endOfMonth);
        self::assertEquals([$changed->getId() => '100.00', $startsLater->getId() => '120.00'], $midMonth);

        /** @var CostPriceResolverInterface $resolver */
        $resolver = self::getContainer()->get(CostPriceResolverInterface::class);
        foreach (['2026-04-10', '2026-04-30'] as $date) {
            $checked = [$changed->getId(), $startsLater->getId(), $closed->getId()];
            $batch = $facade->getCostPricesForListingsOnDate(self::COMPANY_ID, $checked, new \DateTimeImmutable($date));
            foreach ($checked as $listingId) {
                self::assertSame(
                    $resolver->resolve(self::COMPANY_ID, $listingId, new \DateTimeImmutable($date)),
                    $batch[$listingId],
                    sprintf('Пакетный запрос расходится с резолвером для %s на %s', $listingId, $date),
                );
            }
        }

        self::assertSame([], $facade->getCostPricesForListingsOnDate(self::COMPANY_ID, [], new \DateTimeImmutable('2026-04-30')));
    }

    private function company(string $id, int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withId($id)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function listing(Company $company, string $sku): MarketplaceListing
    {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku($sku)
            ->build();
        $this->em->persist($listing);

        return $listing;
    }

    private function cost(string $companyId, MarketplaceListing $listing, string $from, string $price): MarketplaceInventoryCostPrice
    {
        $cost = new MarketplaceInventoryCostPrice(
            Uuid::uuid7()->toString(),
            $companyId,
            $listing,
            new \DateTimeImmutable($from),
            $price,
        );
        $this->em->persist($cost);

        return $cost;
    }
}
