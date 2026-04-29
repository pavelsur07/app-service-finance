<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Action;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Marketplace\Application\Action\ApplyDefaultCostMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultCostMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use App\Marketplace\Infrastructure\Query\MarketplaceCostCategoriesByCodeQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceCostPLMappingsByCostCategoryQuery;
use App\Marketplace\Infrastructure\Query\PLCategoriesByCodeQuery;
use App\Marketplace\Infrastructure\Writer\DefaultCostMappingWriter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class ApplyDefaultCostMappingActionTest extends IntegrationTestCase
{
    public function testApplyCreatesAndFillsAndSkipsSafelyAndIsIdempotent(): void
    {
        $companyId = '22222222-2222-2222-2222-222222222222';
        $company = $this->createCompany($companyId);

        $leaf = $this->createPl($company, 'PL_LEAF', PLCategoryType::LEAF_INPUT);
        $create = $this->createCost($company, 'cost_create');
        $fill = $this->createCost($company, 'cost_fill');
        $existing = $this->createCost($company, 'cost_existing');
        $disabled = $this->createCost($company, 'cost_disabled');

        $fillMapping = new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $fill, null, true);
        $existingMapping = new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $existing, (string) $leaf->getId(), true);
        $disabledMapping = new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $disabled, (string) $leaf->getId(), false);

        $this->em->persist($fillMapping);
        $this->em->persist($existingMapping);
        $this->em->persist($disabledMapping);
        $this->em->flush();

        $action = $this->buildApplyAction('default_cost_mapping_apply.yaml');
        $result = $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, Uuid::uuid7()->toString()));

        self::assertSame(1, $result->getCreatedCount());
        self::assertSame(1, $result->getUpdatedCount());
        self::assertSame(3, $result->getSkippedCount());
        self::assertSame(0, $result->getBlockedCount());

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT cost_category_id, pl_category_id, include_in_pl FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);
        self::assertCount(4, $rows);

        $createPl = $this->em->getConnection()->fetchOne('SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE company_id = :companyId AND cost_category_id = :costId', ['companyId' => $companyId, 'costId' => (string) $create->getId()]);
        self::assertSame((string) $leaf->getId(), (string) $createPl);

        $fillPl = $this->em->getConnection()->fetchOne('SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE id = :id', ['id' => (string) $fillMapping->getId()]);
        self::assertSame((string) $leaf->getId(), (string) $fillPl);

        $beforeCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);
        $secondResult = $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, Uuid::uuid7()->toString()));
        $afterCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);

        self::assertSame($beforeCount, $afterCount);
        self::assertSame(0, $secondResult->getCreatedCount());
    }

    public function testApplyIsBlockedWhenPreviewHasMissingOrInvalidPl(): void
    {
        $companyId = '33333333-3333-3333-3333-333333333333';
        $company = $this->createCompany($companyId);
        $this->createPl($company, 'PL_SUBTOTAL', PLCategoryType::SUBTOTAL);
        $this->createCost($company, 'cost_missing_pl');
        $this->createCost($company, 'cost_invalid_pl');
        $this->em->flush();

        $beforeCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);

        $action = $this->buildApplyAction('default_cost_mapping_apply_blocked.yaml');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Базовый маппинг не может быть применён: есть отсутствующие или невалидные категории ОПиУ.');
        $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, Uuid::uuid7()->toString()));

        $afterCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);
        self::assertSame($beforeCount, $afterCount);
    }

    private function buildApplyAction(string $fixture): ApplyDefaultCostMappingAction
    {
        $connection = $this->em->getConnection();
        $previewAction = new PreviewDefaultCostMappingAction(
            new DefaultCostMappingYamlProvider(__DIR__ . '/../../../../Fixtures/Marketplace/' . $fixture),
            new MarketplaceCostCategoriesByCodeQuery($connection),
            new PLCategoriesByCodeQuery($connection),
            new MarketplaceCostPLMappingsByCostCategoryQuery($connection),
        );

        return new ApplyDefaultCostMappingAction($previewAction, new DefaultCostMappingWriter($connection), $connection, new NullLogger());
    }

    private function createCompany(string $companyId): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(sprintf('%s@example.test', $companyId))->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function createPl(Company $company, string $code, PLCategoryType $type): PLCategory
    {
        $pl = new PLCategory(Uuid::uuid7()->toString(), $company);
        $pl->setName($code)->setCode($code)->setType($type);
        $this->em->persist($pl);

        return $pl;
    }

    private function createCost(Company $company, string $code): MarketplaceCostCategory
    {
        $cost = new MarketplaceCostCategory(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON);
        $cost->setCode($code);
        $cost->setName($code);
        $this->em->persist($cost);

        return $cost;
    }
}
