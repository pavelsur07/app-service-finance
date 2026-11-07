<?php

declare(strict_types=1);

namespace App\Tests\Support\Marketplace;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class OzonRealizationRepairFixture
{
    public const COMPANY = 'b57d7682-505f-4b74-86f8-953d32d59874';
    public const RAW = 'dda3485d-1aa8-48b5-9e12-bc8f06ddb239';

    public static function seed(EntityManagerInterface $em, ProcessOzonRealizationAction $process, CloseMonthStageAction $close, int $saleCopies = 2, bool $withOtherSources = false): string
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($user)->build();
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Test');
        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON);
        $connection->setApiKey('test-key');
        $connection->setProjectDirectionId($project->getId());
        $sales = PLCategoryBuilder::aPLCategory()->forCompany($company)->withCode('REV_SPP_SALES')->withFlow(PLFlow::INCOME)->build();
        $returns = PLCategoryBuilder::aPLCategory()->forCompany($company)->withCode('REV_SPP_RETURNS')->withFlow(PLFlow::EXPENSE)->build();
        $raw = MarketplaceRawDocumentBuilder::aDocument()->withId(self::RAW)->forCompany($company)->withDocumentType('realization')
            ->withPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))->build();
        $sale = ['item' => ['sku' => '123'], 'delivery_commission' => ['price_per_instance' => 10, 'quantity' => 1]];
        $raw->setRawData(['result' => ['header' => ['start_date' => '2026-10-01', 'stop_date' => '2026-10-31'],
            'rows' => [...array_fill(0, $saleCopies, $sale), ['item' => ['sku' => '123'], 'return_commission' => ['price_per_instance' => 2, 'quantity' => 1]]]]]);
        $extra = [];
        $costDocId = null;
        if ($withOtherSources) {
            $cfo = new FinancialResponsibilityCenter(self::COMPANY, 'TEST_CENTER', 'Test center');
            $gross = PLCategoryBuilder::aPLCategory()->forCompany($company)->withCode('REV_NOT_SPP')->withFlow(PLFlow::INCOME)->build();
            $cogs = PLCategoryBuilder::aPLCategory()->forCompany($company)->withCode('COGS_PRODUCT_REV')->withFlow(PLFlow::EXPENSE)->build();
            $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('987')->build();
            $dailySale = new MarketplaceSale(Uuid::uuid7()->toString(), $company, $listing, MarketplaceType::OZON);
            $dailySale->setExternalOrderId('fixture-daily-sale');
            $dailySale->setSaleDate(new \DateTimeImmutable('2026-10-15'));
            $dailySale->setQuantity(1);
            $dailySale->setPricePerUnit('30.00');
            $dailySale->setTotalRevenue('30.00');
            $dailySale->setCostPrice('5.00');
            $extra = [$cfo, $gross, $cogs, $listing, $dailySale,
                new MarketplaceSaleMapping(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON, AmountSource::SALE_GROSS, $gross),
                new MarketplaceSaleMapping(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON, AmountSource::SALE_COST_PRICE, $cogs)];
            foreach ([[PLDocumentSource::MARKETPLACE_WB, PLDocumentStream::REVENUE, $sales, '7.00'], [PLDocumentSource::MARKETPLACE_OZON, PLDocumentStream::COSTS, $cogs, '3.00']] as [$source, $stream, $category, $amount]) {
                $other = new Document(Uuid::uuid7()->toString(), $company);
                $other->setDate(new \DateTimeImmutable('2026-10-31'));
                $other->setType(DocumentType::MARKETPLACE_PL);
                $other->setSource($source);
                $other->setStream($stream);
                $other->setProjectDirection($project);
                $other->setResponsibilityCenterId($cfo->getId());
                $other->addOperation((new DocumentOperation(Uuid::uuid7()->toString()))->setPlCategory($category)->setAmount($amount)->setProjectDirection($project));
                $extra[] = $other;
                if (PLDocumentStream::COSTS === $stream) {
                    $costDocId = $other->getId();
                }
            }
        }
        foreach ([$user, $company, $project, $connection, $sales, $returns, $raw,
            new MarketplaceSaleMapping(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON, AmountSource::SALE_REALIZATION, $sales),
            new MarketplaceSaleMapping(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON, AmountSource::RETURN_REALIZATION, $returns), ...$extra] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $process(self::COMPANY, self::RAW);
        $em->getConnection()->executeStatement('INSERT INTO marketplace_ozon_realizations (id,company_id,listing_id,raw_document_id,sku,offer_id,name,seller_price_per_instance,quantity,total_amount,period_from,period_to,created_at,return_price_per_instance,return_quantity,return_amount) SELECT gen_random_uuid(),company_id,listing_id,raw_document_id,sku,offer_id,name,seller_price_per_instance,quantity,total_amount,period_from,period_to,created_at,return_price_per_instance,return_quantity,return_amount FROM marketplace_ozon_realizations WHERE company_id = ? AND raw_document_id = ?', [self::COMPANY, self::RAW]);
        $close(new CloseMonthStageCommand(self::COMPANY, 'ozon', 2026, 10, CloseStage::SALES_RETURNS->value, (string) $user->getId()));
        if (null !== $costDocId) {
            $em->getConnection()->executeStatement("UPDATE marketplace_month_closes SET stage_costs_status='closed',stage_costs_pl_document_ids = ?::json WHERE company_id = ? AND marketplace = 'ozon' AND year=2026 AND month=10", [json_encode([$costDocId], \JSON_THROW_ON_ERROR), self::COMPANY]);
        }

        return (string) $em->getConnection()->fetchOne('SELECT md5(raw_data::text) FROM marketplace_raw_documents WHERE company_id = ? AND id = ?', [self::COMPANY, self::RAW]);
    }
}
