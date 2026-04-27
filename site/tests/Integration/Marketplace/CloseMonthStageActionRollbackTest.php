<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

// UnprocessedCostsQuery — final, но для теста нужно подменить getControlSum,
// оставив execute() реальным. Разрешение final-обхода вынесено в
// tests/bootstrap.php (allowPaths), чтобы стрим-декор BypassFinals отработал
// до первого autoload класса.

/**
 * Guarantees that CloseMonthStageAction откатывает ВСЮ свою запись при падении
 * контрольной суммы: созданный PLDocument не виден, marketplace_costs.document_id
 * остался NULL у всех строк периода, MarketplaceMonthClose не создан.
 *
 * До обёртки в wrapInTransaction этот тест не проходил — PLDocument и
 * UPDATE document_id коммитились отдельно, а «ручной rollback» чистил только
 * документ, оставляя висящий document_id в marketplace_costs.
 */
final class CloseMonthStageActionRollbackTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000088';
    private const OWNER_ID   = '22222222-2222-2222-2222-000000000088';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR  = 2026;
    private const MONTH = 1;
    private const PERIOD_FROM = '2026-01-01';
    private const PERIOD_TO   = '2026-01-31';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('rollback-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testControlSumMismatchRollsBackEverything(): void
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

        // Два charge-строки; они замаппены, в периоде, document_id IS NULL.
        $this->createCost($costCategory, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($costCategory, '500.00',  MarketplaceCostOperationType::CHARGE, '2026-01-20');
        $this->em->flush();
        $this->em->clear();

        // Override UnprocessedCostsQuery so getControlSum намеренно расходится с
        // handler-ом (он агрегирует execute()). Формула А = реальная + 9999,
        // формула В (handler) считается из execute() как есть → delta ≈ 9999.
        $real = self::getContainer()->get(UnprocessedCostsQuery::class);
        $stub = new class($real) extends UnprocessedCostsQuery {
            public function __construct(private readonly UnprocessedCostsQuery $real)
            {
                // intentionally skip parent ctor: we delegate all work to $real
            }

            public function execute(string $companyId, string $marketplace, string $periodFrom, string $periodTo): array
            {
                return $this->real->execute($companyId, $marketplace, $periodFrom, $periodTo);
            }

            public function getControlSum(string $companyId, string $marketplace, string $periodFrom, string $periodTo, bool $preliminary = false): string
            {
                return bcadd(
                    $this->real->getControlSum($companyId, $marketplace, $periodFrom, $periodTo, $preliminary),
                    '9999.00',
                    2,
                );
            }
        };
        self::getContainer()->set(UnprocessedCostsQuery::class, $stub);

        /** @var CloseMonthStageAction $action */
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
        );

        $caught = null;
        try {
            ($action)($command);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'CloseMonthStageAction должен был бросить RuntimeException на контрольной сумме.');
        self::assertStringContainsString('Контрольная сумма не сошлась', $caught->getMessage());

        $conn = $this->em->getConnection();

        $documentRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(0, $documentRows, 'PLDocument должен быть откачен — в documents ничего нет.');

        $withDocId = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :c AND document_id IS NOT NULL',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(0, $withDocId, 'marketplace_costs.document_id должен остаться NULL после rollback.');

        $costsPresent = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(2, $costsPresent, 'Исходные строки затрат не должны быть удалены rollback-ом.');

        $monthCloseRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM marketplace_month_closes WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(0, $monthCloseRows, 'MarketplaceMonthClose не должен был сохраниться при rollback.');
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
