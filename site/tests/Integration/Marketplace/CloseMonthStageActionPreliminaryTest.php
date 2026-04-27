<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Application\ReopenMonthStageAction;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Проверяет поведение флага CloseMonthStageCommand::preliminary:
 *  - префикс «[Оперативное закрытие …]» в comment у каждой DocumentOperation
 *  - settings.last_close_was_preliminary и preliminary_calculated_at в MarketplaceMonthClose
 *  - сброс флага при финальном закрытии
 *  - default-поведение (preliminary=false) идентично прежнему флоу
 */
final class CloseMonthStageActionPreliminaryTest extends IntegrationTestCase
{
    private const COMPANY_ID  = '11111111-1111-1111-1111-000000000099';
    private const OWNER_ID    = '22222222-2222-2222-2222-000000000099';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR  = 2026;
    private const MONTH = 2;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('preliminary-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testPreliminaryFlagAddsMarkerToEachOperationComment(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(preliminary: true);

        $conn = $this->em->getConnection();

        $documentRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'PLDocument должен быть создан при предзакрытии.');

        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments, 'У документа должны быть операции.');
        foreach ($operationComments as $comment) {
            self::assertIsString($comment);
            self::assertStringStartsWith(
                '[Оперативное закрытие ',
                (string) $comment,
                'Каждая операция должна иметь префикс предзакрытия.',
            );
        }
    }

    public function testPreliminaryFlagSetsSettingsFlag(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(preliminary: true);

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);

        $settings = $monthClose->getSettings();
        self::assertIsArray($settings);
        self::assertTrue(
            $settings['last_close_was_preliminary'] ?? false,
            'Флаг last_close_was_preliminary должен быть true.',
        );

        $calculatedAt = $settings['preliminary_calculated_at'] ?? null;
        self::assertIsString($calculatedAt);
        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $calculatedAt),
            'preliminary_calculated_at должен быть валидным ISO-таймстемпом.',
        );
    }

    public function testFinalCloseResetsPreliminaryFlag(): void
    {
        $this->seedCostsForCosts();

        // 1. Предварительное закрытие
        $this->closeCosts(preliminary: true);
        $afterPreliminary = $this->reloadMonthClose();
        self::assertNotNull($afterPreliminary);
        self::assertTrue($afterPreliminary->isLastCloseWasPreliminary());

        // 2. Переоткрытие (та же стандартная процедура, что у финансиста)
        $this->reopenCosts();

        // 3. Финальное закрытие
        $this->closeCosts(preliminary: false);

        $finalMonthClose = $this->reloadMonthClose();
        self::assertNotNull($finalMonthClose);
        self::assertFalse(
            $finalMonthClose->isLastCloseWasPreliminary(),
            'После финального закрытия флаг должен быть сброшен.',
        );

        $settings = $finalMonthClose->getSettings();
        self::assertNull(
            $settings['preliminary_calculated_at'] ?? 'unset',
            'preliminary_calculated_at должен быть null после финального закрытия.',
        );

        $conn = $this->em->getConnection();
        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments);
        foreach ($operationComments as $comment) {
            self::assertStringNotContainsString(
                '[Оперативное закрытие',
                (string) $comment,
                'У финальных операций не должно быть префикса.',
            );
        }
    }

    public function testDefaultIsFinalClose(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(); // без флага → preliminary=false по дефолту

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);
        self::assertFalse($monthClose->isLastCloseWasPreliminary());

        $conn = $this->em->getConnection();
        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments);
        foreach ($operationComments as $comment) {
            self::assertStringNotContainsString(
                '[Оперативное закрытие',
                (string) $comment,
                'Default-флоу должен закрывать без префикса.',
            );
        }
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

        $this->createCost($costCategory, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-02-10');
        $this->createCost($costCategory, '500.00',  MarketplaceCostOperationType::CHARGE, '2026-02-20');

        $this->em->flush();
        $this->em->clear();
    }

    private function closeCosts(bool $preliminary = false): void
    {
        /** @var CloseMonthStageAction $action */
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: $preliminary,
        );

        ($action)($command);

        $this->em->clear();
    }

    private function reopenCosts(): void
    {
        /** @var ReopenMonthStageAction $action */
        $action = self::getContainer()->get(ReopenMonthStageAction::class);

        ($action)(new ReopenMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS,
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
        $cost->setExternalId('ext-' . Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }
}
