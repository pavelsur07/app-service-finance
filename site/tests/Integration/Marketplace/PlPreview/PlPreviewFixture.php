<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceOzonRealization;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentLineBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Данные января 2026 для предпросмотра ОПиУ. Ожидаемые суммы в знаке документа marketplace_pl:
 *
 * | статья  | wbA   | wbB | ozA  | без листинга | итог  |
 * |---------|-------|-----|------|--------------|-------|
 * | REV     | 1000  | 300 |      |              | 1300  |
 * | RET     | −200  |     |      |              | −200  |
 * | COST    | −70   | −50 |      | −70          | −190  |
 * | REAL    |       |     | 200  | 100          | 300   |
 *
 * COST: начисления 100 (wbA) + 50 (wbB) + 70 (без листинга), сторно 30 (wbA).
 * Не попадают: продажа февраля, неактивное правило выручки с СПП, затрата исключённой из ОПиУ
 * категории (500), затрата категории с правилом без статьи (400), затрата категории без правила (25),
 * затрата без категории (15), реализация Ozon декабрь–январь, продажа другой компании.
 */
final class PlPreviewFixture
{
    public Company $company;
    public User $owner;
    public Company $foreign;
    private User $lastOwner;
    public PLCategory $revenue;
    public PLCategory $returns;
    public PLCategory $costs;
    public PLCategory $realization;
    public MarketplaceListing $wbA;
    public MarketplaceListing $wbB;
    public MarketplaceListing $ozA;
    public MarketplaceCostCategory $excludedCostCategory;
    public MarketplaceCostCategory $unmappedCostCategory;
    public MarketplaceCostCategory $noRuleCostCategory;

    public function __construct(private readonly EntityManagerInterface $em, private readonly int $seed)
    {
        $this->company = $this->company($seed, 'pl-preview');
        $this->owner = $this->lastOwner;
        $foreign = $this->company($seed + 1, 'pl-preview-foreign');
        $this->foreign = $foreign;

        $this->revenue = $this->category('REV', PLFlow::INCOME);
        $this->returns = $this->category('RET', PLFlow::EXPENSE);
        $this->costs = $this->category('COST', PLFlow::EXPENSE);
        $this->realization = $this->category('REAL', PLFlow::INCOME);

        $this->wbA = $this->listing(MarketplaceType::WILDBERRIES, $seed * 10 + 1);
        $this->wbB = $this->listing(MarketplaceType::WILDBERRIES, $seed * 10 + 2);
        $this->ozA = $this->listing(MarketplaceType::OZON, $seed * 10 + 3);

        $this->saleMapping(MarketplaceType::WILDBERRIES, AmountSource::SALE_GROSS, $this->revenue);
        $this->saleMapping(MarketplaceType::WILDBERRIES, AmountSource::SALE_REVENUE, $this->revenue)->setIsActive(false);
        $this->saleMapping(MarketplaceType::WILDBERRIES, AmountSource::RETURN_REFUND, $this->returns)->setIsNegative(true);
        $this->saleMapping(MarketplaceType::OZON, AmountSource::SALE_REALIZATION, $this->realization);
        $this->saleMapping(MarketplaceType::OZON, AmountSource::RETURN_REALIZATION, $this->realization)->setIsNegative(true);

        $this->sale($this->company, $this->wbA, 2, '500.00', '2026-01-10');
        $this->sale($this->company, $this->wbB, 1, '300.00', '2026-01-31');
        $this->sale($this->company, $this->wbA, 1, '999.00', '2026-02-01');
        $foreignListing = $this->listing(MarketplaceType::WILDBERRIES, $seed * 10 + 4, $foreign);
        $this->sale($foreign, $foreignListing, 1, '777.00', '2026-01-10');

        $return = new MarketplaceReturn(Uuid::uuid4()->toString(), $this->company, $this->wbA, MarketplaceType::WILDBERRIES);
        $return->setReturnDate(new \DateTimeImmutable('2026-01-15'));
        $return->setQuantity(1);
        $return->setRefundAmount('200.00');
        $this->em->persist($return);

        $logistics = $this->costCategory('wb_logistics');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $this->company->getId(), $logistics, $this->costs->getId()));
        $this->excludedCostCategory = $this->costCategory('wb_excluded');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $this->company->getId(), $this->excludedCostCategory, $this->costs->getId(), false));
        $this->unmappedCostCategory = $this->costCategory('wb_unmapped');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $this->company->getId(), $this->unmappedCostCategory, null));

        $this->cost($logistics, $this->wbA, '100.00', MarketplaceCostOperationType::CHARGE);
        $this->cost($logistics, $this->wbB, '50.00', MarketplaceCostOperationType::CHARGE);
        $this->cost($logistics, null, '70.00', MarketplaceCostOperationType::CHARGE);
        $this->cost($logistics, $this->wbA, '30.00', MarketplaceCostOperationType::STORNO);
        $this->cost($this->excludedCostCategory, $this->wbA, '500.00', MarketplaceCostOperationType::CHARGE);
        $this->cost($this->unmappedCostCategory, null, '400.00', MarketplaceCostOperationType::CHARGE);
        $this->noRuleCostCategory = $this->costCategory('wb_no_rule');
        $this->cost($this->noRuleCostCategory, $this->wbB, '25.00', MarketplaceCostOperationType::CHARGE);
        $this->cost(null, null, '15.00', MarketplaceCostOperationType::CHARGE);

        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withIndex($seed)
            ->build();
        $this->em->persist($rawDocument);
        $posted = $this->realization($rawDocument, $this->ozA, '100.00', 3, '2026-01-01', '2026-01-31');
        $posted->setReturnCommission(100.0, 1);
        // Уже проведённая строка тоже входит в предпросмотр.
        $posted->setPlDocumentId(Uuid::uuid4()->toString());
        $this->realization($rawDocument, null, '50.00', 2, '2026-01-01', '2026-01-31');
        $this->realization($rawDocument, $this->ozA, '1000.00', 1, '2025-12-15', '2026-01-14');

        $this->em->flush();
    }

    private function company(int $seed, string $email): Company
    {
        $suffix = sprintf('%012d', $seed);
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-'.$suffix)->withEmail($email.$seed.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-'.$suffix)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->lastOwner = $owner;

        return $company;
    }

    private function category(string $code, PLFlow $flow): PLCategory
    {
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($this->company)
            ->withName($code)
            ->withCode($code)
            ->withType(PLCategoryType::LEAF_INPUT)
            ->withFlow($flow)
            ->build();
        $this->em->persist($category);

        return $category;
    }

    private function listing(MarketplaceType $marketplace, int $index, ?Company $company = null): MarketplaceListing
    {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company ?? $this->company)
            ->withMarketplace($marketplace)
            ->withIndex($index)
            ->withMarketplaceSku('sku-'.$index)
            ->build();
        $this->em->persist($listing);

        return $listing;
    }

    private function saleMapping(MarketplaceType $marketplace, AmountSource $source, PLCategory $category): MarketplaceSaleMapping
    {
        $mapping = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $this->company, $marketplace, $source, $category);
        $this->em->persist($mapping);

        return $mapping;
    }

    private function sale(Company $company, MarketplaceListing $listing, int $quantity, string $price, string $date): void
    {
        $this->em->persist(MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($listing)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withExternalOrderId('ord-'.Uuid::uuid4()->toString())
            ->withSaleDate(new \DateTimeImmutable($date))
            ->withQuantity($quantity)
            ->withPricePerUnit($price)
            ->withTotalRevenue(bcmul($price, (string) $quantity, 2))
            ->build());
    }

    private function costCategory(string $code): MarketplaceCostCategory
    {
        $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $this->company, MarketplaceType::WILDBERRIES);
        $category->setName($code);
        $category->setCode($code);
        $this->em->persist($category);

        return $category;
    }

    private function cost(?MarketplaceCostCategory $category, ?MarketplaceListing $listing, string $amount, MarketplaceCostOperationType $type): void
    {
        $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $this->company, MarketplaceType::WILDBERRIES, $category);
        $cost->setListing($listing);
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable('2026-01-12'));
        $cost->setOperationType($type);
        $this->em->persist($cost);
    }

    private function realization(
        MarketplaceRawDocument $rawDocument,
        ?MarketplaceListing $listing,
        string $price,
        int $quantity,
        string $from,
        string $to,
    ): MarketplaceOzonRealization {
        $realization = new MarketplaceOzonRealization(
            Uuid::uuid4()->toString(),
            (string) $this->company->getId(),
            $rawDocument,
            'sku-'.Uuid::uuid4()->toString(),
            $price,
            $quantity,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
        );
        $realization->setListing($listing);
        $this->em->persist($realization);

        return $realization;
    }

    /**
     * Рекламная категория WB «Продвижение» с правилом на статью COST и учётными строками.
     *
     * @param list<array{0: string, 1: ?MarketplaceCostOperationType}> $extraRows ещё строки: сумма, тип операции
     */
    public function addWbPromotion(bool $includeInPl, string $ledger, array $extraRows = [], ?Company $company = null): void
    {
        $company ??= $this->company;
        $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES);
        $category->setName('WB Продвижение');
        $category->setCode('wb_okazanie_uslug_wb_prodvizhenie');
        $this->em->persist($category);
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $category, $this->costs->getId(), $includeInPl));
        foreach ([[$ledger, MarketplaceCostOperationType::CHARGE], ...$extraRows] as [$amount, $type]) {
            $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, $category);
            $cost->setAmount($amount);
            $cost->setCostDate(new \DateTimeImmutable('2026-01-18'));
            $cost->setOperationType($type);
            $this->em->persist($cost);
        }
        $this->em->flush();
    }

    /**
     * @param array<string, string> $byListing имя листинга фикстуры → расход
     */
    public function addWbAdStatistics(array $byListing, string $unallocated): void
    {
        $raw = AdRawDocumentBuilder::aRawDocument()->withIndex($this->seed)->withCompanyId((string) $this->company->getId())->withMarketplace(MarketplaceType::WILDBERRIES)->build();
        $this->em->persist($raw);
        $index = 0;
        foreach ($byListing as $name => $amount) {
            $document = $this->adDocument(++$index, $amount, $raw->getId());
            $listing = 'wbA' === $name ? $this->wbA : $this->wbB;
            $this->em->persist(AdDocumentLineBuilder::aLine()->withIndex($this->seed * 10 + $index)->withAdDocument($document)->withListingId($listing->getId())->withCost($amount)->build());
        }
        if (0 !== bccomp($unallocated, '0', 2)) {
            $this->adDocument(++$index, $unallocated, $raw->getId());
        }
        $this->em->flush();
    }

    private function adDocument(int $index, string $cost, string $rawId): AdDocument
    {
        $document = AdDocumentBuilder::aDocument()
            ->withIndex($this->seed * 10 + $index)
            ->withCompanyId((string) $this->company->getId())
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withReportDate(new \DateTimeImmutable('2026-01-15'))
            ->withTotalCost($cost)
            ->withAdRawDocumentId($rawId)
            ->build();
        $this->em->persist($document);

        return $document;
    }
}
