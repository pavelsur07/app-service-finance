<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Action;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Marketplace\Application\Action\ApplyDefaultCostMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultCostMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Service\DefaultCostMappingSiblingResolver;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use App\Marketplace\Infrastructure\Query\CompanyCostPlTargetsQuery;
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

        try {
            $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, Uuid::uuid7()->toString()));
            self::fail('DomainException was expected.');
        } catch (\DomainException $exception) {
            self::assertSame('Базовый маппинг не может быть применён: есть отсутствующие или невалидные категории ОПиУ.', $exception->getMessage());
        }

        $afterCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings WHERE company_id = :companyId', ['companyId' => $companyId]);
        self::assertSame($beforeCount, $afterCount);
    }

    /**
     * Ночной прогон: правило без статьи ОПиУ у компании не должно мешать
     * остальным — иначе одна отсутствующая статья оставляет без маппинга все
     * новые категории компании.
     */
    public function testPartialApplyCreatesAvailableRulesAndBlocksOnlyMissingOnes(): void
    {
        $companyId = '44444444-4444-4444-4444-444444444444';
        $company = $this->createCompany($companyId);
        $leaf = $this->createPl($company, 'PL_LEAF', PLCategoryType::LEAF_INPUT);
        $this->createPl($company, 'PL_SUBTOTAL', PLCategoryType::SUBTOTAL);
        $create = $this->createCost($company, 'cost_create');
        $missing = $this->createCost($company, 'cost_missing_pl');
        $invalid = $this->createCost($company, 'cost_invalid_pl');
        $this->em->flush();

        $action = $this->buildApplyAction('default_cost_mapping_apply_partial.yaml');
        $result = $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, 'cron', partial: true));

        self::assertSame(['cost_create'], $result->getCreatedCostCodes());
        self::assertSame(['cost_missing_pl', 'cost_invalid_pl'], $result->getBlockedCostCodes());

        $mapped = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT cost_category_id, pl_category_id FROM marketplace_cost_pl_mappings WHERE company_id = :companyId',
            ['companyId' => $companyId],
        );
        self::assertSame([(string) $create->getId() => (string) $leaf->getId()], $mapped);
        self::assertArrayNotHasKey((string) $missing->getId(), $mapped);
        self::assertArrayNotHasKey((string) $invalid->getId(), $mapped);
    }

    /**
     * Компания со своим деревом ОПиУ: статьи шаблона PL_MISSING нет, но затраты
     * этого типа она единогласно относит в «Свою строку» — новая затрата идёт туда же.
     * Разнобой образцов (PL_OTHER_MISSING) — решения нет, правило остаётся blocked.
     */
    public function testPartialApplyInfersLineFromUnanimousCompanySamples(): void
    {
        $companyId = '55555555-5555-5555-5555-555555555555';
        $company = $this->createCompany($companyId);
        $own = $this->createPl($company, 'OWN_LINE', PLCategoryType::LEAF_INPUT);
        $other = $this->createPl($company, 'OTHER_LINE', PLCategoryType::LEAF_INPUT);

        foreach (['cost_sample_a' => $own, 'cost_sample_b' => $own, 'cost_conflict_a' => $own, 'cost_conflict_b' => $other] as $code => $line) {
            $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $this->createCost($company, $code), (string) $line->getId(), true));
        }
        // Отключённое правило — не образец, и разнобоя не создаёт.
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $this->createCost($company, 'cost_sample_disabled'), (string) $other->getId(), false));

        $new = $this->createCost($company, 'cost_new');
        $newEmpty = $this->createCost($company, 'cost_new_empty');
        $emptyMapping = new MarketplaceCostPLMapping(Uuid::uuid7()->toString(), $companyId, $newEmpty, null, true);
        $this->em->persist($emptyMapping);
        $conflictNew = $this->createCost($company, 'cost_conflict_new');
        $this->em->flush();

        $action = $this->buildApplyAction('default_cost_mapping_apply_sibling.yaml');
        $result = $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, 'cron', partial: true));

        self::assertSame(['cost_new'], $result->getCreatedCostCodes());
        self::assertSame(['cost_new_empty'], $result->getUpdatedCostCodes());
        self::assertSame(['cost_new', 'cost_new_empty'], $result->getInferredCostCodes());
        self::assertSame(['cost_conflict_new'], $result->getBlockedCostCodes());

        $plOf = fn (string $costId): mixed => $this->em->getConnection()->fetchOne(
            'SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE company_id = :companyId AND cost_category_id = :costId',
            ['companyId' => $companyId, 'costId' => $costId],
        );
        self::assertSame((string) $own->getId(), (string) $plOf((string) $new->getId()));
        self::assertSame((string) $own->getId(), (string) $plOf((string) $newEmpty->getId()));
        self::assertFalse($plOf((string) $conflictNew->getId()));

        // Полный режим (кнопка UI) по образцу не достраивает — по-прежнему блокируется.
        $this->expectException(\DomainException::class);
        $action(new ApplyDefaultCostMappingCommand($companyId, MarketplaceType::OZON->value, 'user'));
    }

    private function buildApplyAction(string $fixture): ApplyDefaultCostMappingAction
    {
        $connection = $this->em->getConnection();
        $previewAction = new PreviewDefaultCostMappingAction(
            new DefaultCostMappingYamlProvider(__DIR__.'/../../../../Fixtures/Marketplace/'.$fixture),
            new MarketplaceCostCategoriesByCodeQuery($connection),
            new PLCategoriesByCodeQuery($connection),
            new MarketplaceCostPLMappingsByCostCategoryQuery($connection),
        );

        return new ApplyDefaultCostMappingAction(
            $previewAction,
            new DefaultCostMappingWriter($connection),
            new CompanyCostPlTargetsQuery($connection),
            new DefaultCostMappingSiblingResolver(),
            $connection,
            new NullLogger(),
        );
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
