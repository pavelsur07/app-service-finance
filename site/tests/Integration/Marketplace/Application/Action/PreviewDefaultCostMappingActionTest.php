<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Action;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Marketplace\Application\Action\PreviewDefaultCostMappingAction;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use App\Marketplace\Infrastructure\Query\MarketplaceCostCategoriesByCodeQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceCostPLMappingsByCostCategoryQuery;
use App\Marketplace\Infrastructure\Query\PLCategoriesByCodeQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PreviewDefaultCostMappingActionTest extends IntegrationTestCase
{
    public function testPreviewStatusesSummaryAndReadonly(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $company = $this->createCompany($companyId);

        $leaf = $this->createPl($company, 'PL_LEAF', PLCategoryType::LEAF_INPUT);
        $subtotal = $this->createPl($company, 'PL_SUBTOTAL', PLCategoryType::SUBTOTAL);

        $create = $this->createCost($company, 'cost_create');
        $fill = $this->createCost($company, 'cost_fill');
        $existing = $this->createCost($company, 'cost_existing');
        $disabled = $this->createCost($company, 'cost_disabled');
        $missingPl = $this->createCost($company, 'cost_missing_pl');
        $invalidPl = $this->createCost($company, 'cost_invalid_pl');

        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $fill, null, true));
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $existing, (string) $leaf->getId(), true));
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $disabled, (string) $leaf->getId(), false));

        $this->em->flush();

        $beforeCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);

        $action = new PreviewDefaultCostMappingAction(
            new DefaultCostMappingYamlProvider(__DIR__ . '/../../../../Fixtures/Marketplace/default_cost_mapping_preview.yaml'),
            new MarketplaceCostCategoriesByCodeQuery($this->em->getConnection()),
            new PLCategoriesByCodeQuery($this->em->getConnection()),
            new MarketplaceCostPLMappingsByCostCategoryQuery($this->em->getConnection()),
        );

        $result = $action(new PreviewDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value));

        self::assertSame(7, $result->getTotal());
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::WILL_CREATE));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::WILL_FILL_EMPTY));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::SKIPPED_EXISTING));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::SKIPPED_DISABLED));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::MISSING_COST_CATEGORY));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY));
        self::assertSame(1, $result->getCountByStatus(DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY));
        self::assertTrue($result->hasBlockingIssues());
        self::assertSame(1, $result->getSummary()[DefaultCostMappingPreviewStatus::WILL_CREATE->value]);

        $afterCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);
        self::assertSame($beforeCount, $afterCount);
        self::assertNotNull($create);
        self::assertNotNull($missingPl);
        self::assertNotNull($invalidPl);
        self::assertNotNull($subtotal);
    }

    public function testEmptyRuleSet(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111112';
        $this->createCompany($companyId);

        $action = new PreviewDefaultCostMappingAction(
            new DefaultCostMappingYamlProvider(__DIR__ . '/../../../../Fixtures/Marketplace/empty_default_cost_mapping.yaml'),
            new MarketplaceCostCategoriesByCodeQuery($this->em->getConnection()),
            new PLCategoriesByCodeQuery($this->em->getConnection()),
            new MarketplaceCostPLMappingsByCostCategoryQuery($this->em->getConnection()),
        );

        $result = $action(new PreviewDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value));
        self::assertSame(0, $result->getTotal());
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
