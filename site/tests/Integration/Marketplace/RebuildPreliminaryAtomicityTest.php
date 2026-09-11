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
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\PreliminaryRebuildFlagQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Переоткрытие и повторное закрытие этапа обязаны быть атомарными.
 *
 * Сценарий, из-за которого это понадобилось: правка Ozon убирает последние
 * строки этапа за исторический месяц. Пересбор переоткрывает этап — а
 * переоткрытие удаляет документ ОПиУ, — после чего закрытие отказывает, потому
 * что закрывать нечего. Без общей транзакции документ оставался бы удалённым,
 * этап — в REOPENED, и ночной пересбор такой этап больше не выбирает: документ
 * исчезал бы насовсем до ручного вмешательства.
 */
final class RebuildPreliminaryAtomicityTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000b1';
    private const OWNER_ID = '22222222-2222-2222-2222-0000000000b1';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const YEAR = 2026;
    private const MONTH = 2;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('rebuild-atomicity-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testEmptiedStageKeepsItsDocumentInsteadOfLosingIt(): void
    {
        $category = $this->seedCost();
        $this->closeCostsPreliminarily();

        $connection = $this->em->getConnection();
        $documentsAfterClose = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentsAfterClose, 'Предзакрытие должно было создать документ.');

        // Период отмечен к пересбору — так его помечает замена строк.
        $flagQuery = self::getContainer()->get(PreliminaryRebuildFlagQuery::class);
        $flagQuery->mark(self::COMPANY_ID, self::MARKETPLACE, self::YEAR, self::MONTH, CloseStage::COSTS);

        // Правка Ozon убрала последние строки этапа.
        $connection->executeStatement(
            'DELETE FROM marketplace_costs WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        $this->em->clear();

        $action = self::getContainer()->get(RebuildPreliminaryForPeriodAction::class);
        ($action)(new RebuildPreliminaryForPeriodCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE->value,
            year: self::YEAR,
            month: self::MONTH,
            actorUserId: self::OWNER_ID,
            stages: [CloseStage::COSTS->value],
        ));

        $this->em->clear();

        $documentsAfterRebuild = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentsAfterRebuild, 'Документ обязан пережить неудавшийся пересбор.');

        $repository = self::getContainer()->get(MarketplaceMonthCloseRepository::class);
        $monthClose = $repository->findByPeriod(self::COMPANY_ID, self::MARKETPLACE, self::YEAR, self::MONTH);

        self::assertNotNull($monthClose);
        self::assertSame(
            MonthCloseStageStatus::CLOSED,
            $monthClose->getStageStatus(CloseStage::COSTS),
            'Этап не должен застрять в REOPENED: иначе ночной пересбор его больше не выберет.',
        );

        // Отметка обязана пережить неудачу: снять её значило бы потерять период
        // навсегда — выборка его больше не вернёт, даже когда причину устранят.
        $flag = $connection->fetchOne(
            "SELECT settings->'needs_preliminary_rebuild'->>'costs' FROM marketplace_month_closes WHERE company_id = :c",
            ['c' => self::COMPANY_ID],
        );
        self::assertSame('true', $flag, 'Отметка о пересборе не должна сниматься при неудаче.');

        unset($category);
    }

    private function seedCost(): MarketplaceCostCategory
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

        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $costCategory,
        );
        $cost->setAmount('1000.00');
        $cost->setCostDate(new \DateTimeImmutable('2026-02-10'));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('ext-'.Uuid::uuid4()->toString());
        $this->em->persist($cost);

        $this->em->flush();
        $this->em->clear();

        return $costCategory;
    }

    private function closeCostsPreliminarily(): void
    {
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        ($action)(new CloseMonthStageCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE->value,
            year: self::YEAR,
            month: self::MONTH,
            stage: CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: true,
        ));

        $this->em->clear();
    }
}
