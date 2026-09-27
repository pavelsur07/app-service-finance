<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Action;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Application\Action\DeleteCostCategoryAction;
use App\Marketplace\Application\Action\UpdateCostPLMappingAction;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\CostCategoryNotFoundException;
use App\Marketplace\Exception\CostMappingPLCategoryNotFoundException;
use App\Marketplace\Exception\SystemCostCategoryDeletionException;
use App\Marketplace\Infrastructure\Query\CostPLMappingListQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class CostPLMappingRowEditTest extends IntegrationTestCase
{
    private const COMPANY_ID = '44444444-4444-4444-4444-444444444444';
    private const FOREIGN_COMPANY_ID = '55555555-5555-5555-5555-555555555555';

    public function testCreatesMappingKeepingSortOrder(): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $cost = $this->createCost($company, 'cost_a');
        $pl = $this->createPl($company, 'PL_A');
        $this->em->flush();

        $this->update((string) $cost->getId(), (string) $pl->getId(), false, 30);

        $row = $this->existingMappingRow((string) $cost->getId());
        self::assertSame((string) $pl->getId(), $row['pl_category_id']);
        self::assertFalse((bool) $row['include_in_pl']);
        self::assertSame(30, (int) $row['sort_order'], 'Порядок при создании маппинга терялся в старом bulk-save.');
    }

    public function testUpdatesExistingMappingAndSkipsWriteWithoutChanges(): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $cost = $this->createCost($company, 'cost_a');
        $pl = $this->createPl($company, 'PL_A');
        $mapping = new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), self::COMPANY_ID, $cost, null);
        $this->em->persist($mapping);
        $this->em->flush();
        $this->connection->executeStatement(
            "UPDATE marketplace_cost_pl_mappings SET updated_at = '2026-01-01 00:00:00' WHERE id = :id",
            ['id' => $mapping->getId()],
        );
        $this->em->clear();

        $this->update((string) $cost->getId(), (string) $pl->getId(), true, 10);
        $afterChange = $this->existingMappingRow((string) $cost->getId());
        self::assertSame((string) $pl->getId(), $afterChange['pl_category_id']);
        self::assertSame(10, (int) $afterChange['sort_order']);
        self::assertNotSame('2026-01-01 00:00:00', $afterChange['updated_at']);

        $this->connection->executeStatement(
            "UPDATE marketplace_cost_pl_mappings SET updated_at = '2026-01-01 00:00:00' WHERE id = :id",
            ['id' => $mapping->getId()],
        );
        $this->em->clear();

        $this->update((string) $cost->getId(), (string) $pl->getId(), true, 10);
        self::assertSame('2026-01-01 00:00:00', $this->existingMappingRow((string) $cost->getId())['updated_at']);
    }

    public function testRejectsForeignCostCategory(): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $foreign = $this->createCompany(self::FOREIGN_COMPANY_ID);
        $foreignCost = $this->createCost($foreign, 'cost_foreign');
        $pl = $this->createPl($company, 'PL_A');
        $this->em->flush();

        try {
            $this->update((string) $foreignCost->getId(), (string) $pl->getId(), true, 0);
            self::fail('Чужая категория затрат принята.');
        } catch (CostCategoryNotFoundException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings'));
    }

    #[DataProvider('invalidPlCategoryProvider')]
    public function testRejectsForeignOrMalformedPlCategory(bool $foreign): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $foreignCompany = $this->createCompany(self::FOREIGN_COMPANY_ID);
        $cost = $this->createCost($company, 'cost_a');
        $foreignPl = $this->createPl($foreignCompany, 'PL_FOREIGN');
        $this->em->flush();

        $this->expectException(CostMappingPLCategoryNotFoundException::class);

        $this->update((string) $cost->getId(), $foreign ? (string) $foreignPl->getId() : 'not-a-uuid', true, 0);
    }

    /** @return iterable<string, array{bool}> */
    public static function invalidPlCategoryProvider(): iterable
    {
        yield 'чужая компания' => [true];
        yield 'не UUID' => [false];
    }

    public function testDeleteRemovesCustomCategoryWithMappingAndRejectsSystemAndForeign(): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $foreign = $this->createCompany(self::FOREIGN_COMPANY_ID);
        $custom = $this->createCost($company, 'cost_custom');
        $system = $this->createCost($company, 'cost_system');
        $system->setIsSystem(true);
        $foreignCost = $this->createCost($foreign, 'cost_foreign');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), self::COMPANY_ID, $custom, null));
        $this->em->flush();

        $delete = self::getContainer()->get(DeleteCostCategoryAction::class);

        $delete(self::COMPANY_ID, (string) $custom->getId());
        self::assertNull($this->mappingRow((string) $custom->getId()));
        self::assertNotNull($this->connection->fetchOne(
            'SELECT deleted_at FROM marketplace_cost_categories WHERE id = :id',
            ['id' => $custom->getId()],
        ));

        try {
            $delete(self::COMPANY_ID, (string) $system->getId());
            self::fail('Системная категория удалена.');
        } catch (SystemCostCategoryDeletionException) {
        }

        $this->expectException(CostCategoryNotFoundException::class);
        $delete(self::COMPANY_ID, (string) $foreignCost->getId());
    }

    public function testListQueryReturnsActiveCompanyRowsWithMappingFilteredByMarketplace(): void
    {
        $company = $this->createCompany(self::COMPANY_ID);
        $foreign = $this->createCompany(self::FOREIGN_COMPANY_ID);
        $mapped = $this->createCost($company, 'b_mapped');
        $this->createCost($company, 'a_unmapped');
        $this->createCost($company, 'c_deleted')->softDelete();
        $this->createCost($company, 'd_wb', MarketplaceType::WILDBERRIES);
        $this->createCost($foreign, 'foreign');
        $pl = $this->createPl($company, 'PL_A');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), self::COMPANY_ID, $mapped, (string) $pl->getId(), false, 20));
        $this->em->flush();

        $query = self::getContainer()->get(CostPLMappingListQuery::class);

        $rows = $query->fetch(self::COMPANY_ID, MarketplaceType::OZON);
        self::assertSame(['a_unmapped', 'b_mapped'], array_map(static fn ($r) => $r->code, $rows));

        [$unmapped, $mappedRow] = $rows;
        self::assertFalse($unmapped->hasMapping);
        self::assertNull($unmapped->plCategoryId);
        self::assertTrue($unmapped->includeInPl);
        self::assertTrue($mappedRow->hasMapping);
        self::assertSame((string) $pl->getId(), $mappedRow->plCategoryId);
        self::assertFalse($mappedRow->includeInPl);
        self::assertSame(20, $mappedRow->sortOrder);

        self::assertCount(3, $query->fetch(self::COMPANY_ID, null));
    }

    private function update(string $costCategoryId, ?string $plCategoryId, bool $includeInPl, int $sortOrder): void
    {
        $action = self::getContainer()->get(UpdateCostPLMappingAction::class);
        $action(self::COMPANY_ID, $costCategoryId, $plCategoryId, $includeInPl, $sortOrder);
    }

    /** @return array<string, mixed> */
    private function existingMappingRow(string $costCategoryId): array
    {
        $row = $this->mappingRow($costCategoryId);
        self::assertNotNull($row, 'Маппинг не создан.');

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function mappingRow(string $costCategoryId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pl_category_id, include_in_pl, sort_order, updated_at FROM marketplace_cost_pl_mappings WHERE cost_category_id = :id',
            ['id' => $costCategoryId],
        );

        return false === $row ? null : $row;
    }

    private function createCompany(string $companyId): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(sprintf('%s@example.test', $companyId))->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function createPl(Company $company, string $code): PLCategory
    {
        $pl = new PLCategory(Uuid::uuid7()->toString(), $company);
        $pl->setName($code)->setCode($code);
        $this->em->persist($pl);

        return $pl;
    }

    private function createCost(Company $company, string $code, MarketplaceType $marketplace = MarketplaceType::OZON): MarketplaceCostCategory
    {
        $cost = new MarketplaceCostCategory(Uuid::uuid7()->toString(), $company, $marketplace);
        $cost->setCode($code);
        $cost->setName($code);
        $this->em->persist($cost);

        return $cost;
    }
}
