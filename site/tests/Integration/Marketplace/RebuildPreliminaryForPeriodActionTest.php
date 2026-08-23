<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Сценарии оркестратора предзакрытия.
 * Проверяется только COSTS-этап — для SALES_RETURNS нужен sale, что выходит
 * за рамки этого теста; orchestration-логика (skip/reopen/close) идентична для обоих этапов.
 */
final class RebuildPreliminaryForPeriodActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000aa';
    private const OWNER_ID = '22222222-2222-2222-2222-0000000000aa';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR = 2026;
    private const MONTH = 3;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('rebuild-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testHappyPathPendingToPreliminary(): void
    {
        $this->seedCostsForCosts();

        $this->rebuild();

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);
        self::assertSame(
            MonthCloseStageStatus::CLOSED,
            $monthClose->getStageStatus(CloseStage::COSTS),
            'COSTS должен стать CLOSED после первого rebuild.',
        );
        self::assertTrue(
            $monthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS),
            'Флаг last_close_was_preliminary должен быть true.',
        );

        $documentRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'Должен быть создан 1 PLDocument.');
    }

    public function testReopensAndClosesWhenPreviousWasPreliminary(): void
    {
        $this->seedCostsForCosts();

        $this->rebuild();
        $first = $this->reloadMonthClose();
        self::assertNotNull($first);
        $firstClosedAt = $first->getStageCostsClosedAt();
        self::assertNotNull($firstClosedAt);

        sleep(1); // чтобы closedAt отличался

        $this->rebuild();
        $second = $this->reloadMonthClose();
        self::assertNotNull($second);
        self::assertSame(
            MonthCloseStageStatus::CLOSED,
            $second->getStageStatus(CloseStage::COSTS),
            'После повторного rebuild этап остаётся CLOSED.',
        );
        self::assertTrue($second->isStageLastCloseWasPreliminary(CloseStage::COSTS));

        // Документ должен быть один — старый удалён, новый создан.
        $documentRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'Должен быть ровно 1 PLDocument после повторного rebuild.');
    }

    public function testSkipsWhenStageClosedManually(): void
    {
        $this->seedCostsForCosts();

        // Финальное закрытие напрямую через CloseMonthStageAction (preliminary=false).
        $this->closeFinal();
        $beforeRebuild = $this->reloadMonthClose();
        self::assertNotNull($beforeRebuild);
        self::assertFalse($beforeRebuild->isStageLastCloseWasPreliminary(CloseStage::COSTS));
        $beforeDocIds = $beforeRebuild->getStagePLDocumentIds(CloseStage::COSTS);

        // rebuild должен пропустить финально закрытый этап.
        $this->rebuild();

        $after = $this->reloadMonthClose();
        self::assertNotNull($after);
        self::assertSame(
            MonthCloseStageStatus::CLOSED,
            $after->getStageStatus(CloseStage::COSTS),
        );
        self::assertFalse(
            $after->isStageLastCloseWasPreliminary(CloseStage::COSTS),
            'Per-stage флаг для COSTS должен остаться false.',
        );
        self::assertSame(
            $beforeDocIds,
            $after->getStagePLDocumentIds(CloseStage::COSTS),
            'Document IDs не должны измениться после rebuild финально закрытого этапа.',
        );
    }

    public function testSkipsWhenPreflightFails(): void
    {
        $this->seedBlockingOtherServiceCost();

        $this->rebuild();

        // Этап должен остаться в исходном PENDING состоянии (или вовсе без записи).
        $monthClose = $this->reloadMonthClose();
        if (null !== $monthClose) {
            self::assertNotSame(
                MonthCloseStageStatus::CLOSED,
                $monthClose->getStageStatus(CloseStage::COSTS),
                'COSTS не должен закрыться при провальном preflight.',
            );
        }

        $documentRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(0, $documentRows, 'PLDocument не должен создаваться при провальном preflight.');
    }

    public function testPreservesExistingPreliminaryWhenPreflightFailsBeforeReopen(): void
    {
        $this->seedCostsForCosts();
        $this->rebuild();

        $before = $this->reloadMonthClose();
        self::assertNotNull($before);
        $documentIds = $before->getStagePLDocumentIds(CloseStage::COSTS);
        self::assertNotSame([], $documentIds);

        /** @var Company $managedCompany */
        $managedCompany = $this->em->find(Company::class, self::COMPANY_ID);
        $this->company = $managedCompany;
        $this->seedBlockingOtherServiceCost();

        $this->rebuild();

        $after = $this->reloadMonthClose();
        self::assertNotNull($after);
        self::assertSame(MonthCloseStageStatus::CLOSED, $after->getStageStatus(CloseStage::COSTS));
        self::assertTrue($after->isStageLastCloseWasPreliminary(CloseStage::COSTS));
        self::assertSame($documentIds, $after->getStagePLDocumentIds(CloseStage::COSTS));

        $documentRows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'Существующий PLDocument должен сохраниться при провальном preflight.');
    }

    public function testSalesReturnsPreliminaryReopensDespiteExpectedClosedStageErrors(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Себестоимость продаж Ozon');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(self::MARKETPLACE)
            ->withMarketplaceSku('sales-rebuild-listing')
            ->build();
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            AmountSource::SALE_COST_PRICE,
            $plCategory,
        );
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($this->company)
            ->forListing($listing)
            ->withSaleDate(new \DateTimeImmutable('2026-03-10'))
            ->withCostPrice('100.00')
            ->build();
        foreach ([$plCategory, $listing, $mapping, $sale] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $this->rebuild([CloseStage::SALES_RETURNS->value]);
        $first = $this->reloadMonthClose();
        self::assertNotNull($first);
        $firstDocumentIds = $first->getStagePLDocumentIds(CloseStage::SALES_RETURNS);
        self::assertNotSame([], $firstDocumentIds);

        $this->rebuild([CloseStage::SALES_RETURNS->value]);
        $second = $this->reloadMonthClose();
        self::assertNotNull($second);
        self::assertSame(MonthCloseStageStatus::CLOSED, $second->getStageStatus(CloseStage::SALES_RETURNS));
        self::assertTrue($second->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS));
        self::assertNotSame($firstDocumentIds, $second->getStagePLDocumentIds(CloseStage::SALES_RETURNS));
    }

    // --- helpers ---

    private function seedCostsForCosts(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Логистика Ozon');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $costCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $costCategory->setCode('ozon_logistic_direct');
        $costCategory->setName('Логистика до покупателя');
        $this->em->persist($costCategory);

        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $costCategory,
            $plCategory->getId(),
            true,
        );
        $this->em->persist($mapping);

        $this->createCost($costCategory, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-03-10');
        $this->createCost($costCategory, '500.00', MarketplaceCostOperationType::CHARGE, '2026-03-20');

        $this->em->flush();
        $this->em->clear();
    }

    private function seedBlockingOtherServiceCost(): void
    {
        // include_in_pl=true + ozon_other_service is a blocking preflight error.
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Прочее Ozon');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $costCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $costCategory->setCode('ozon_other_service');
        $costCategory->setName('Прочая услуга Ozon');
        $this->em->persist($costCategory);

        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $costCategory,
            $plCategory->getId(),
            true,
        );
        $this->em->persist($mapping);

        $this->createCost($costCategory, '100.00', MarketplaceCostOperationType::CHARGE, '2026-03-15');
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param list<string>|null $stages
     */
    private function rebuild(?array $stages = null): void
    {
        /** @var RebuildPreliminaryForPeriodAction $action */
        $action = self::getContainer()->get(RebuildPreliminaryForPeriodAction::class);

        ($action)(new RebuildPreliminaryForPeriodCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year: self::YEAR,
            month: self::MONTH,
            actorUserId: self::OWNER_ID,
            stages: $stages,
        ));

        $this->em->clear();
    }

    private function closeFinal(): void
    {
        /** @var CloseMonthStageAction $action */
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        ($action)(new CloseMonthStageCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year: self::YEAR,
            month: self::MONTH,
            stage: CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: false,
        ));

        $this->em->clear();
    }

    private function reloadMonthClose(): ?MarketplaceMonthClose
    {
        /** @var MarketplaceMonthCloseRepository $repo */
        $repo = self::getContainer()->get(MarketplaceMonthCloseRepository::class);

        return $repo->findByPeriod(
            self::COMPANY_ID,
            self::MARKETPLACE,
            self::YEAR,
            self::MONTH,
        );
    }

    private function createCost(
        MarketplaceCostCategory $category,
        string $amount,
        MarketplaceCostOperationType $operationType,
        string $costDate,
    ): MarketplaceCost {
        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $category,
        );
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($costDate));
        $cost->setOperationType($operationType);
        $cost->setExternalId('ext-'.Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }
}
