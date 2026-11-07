<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAnalytics\UnitEconomyCostMappingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * Страница «Настройки» аналитики маркетплейсов: модуль assets/pages/cost_mappings.js читает
 * адреса API из data-атрибутов, удаление идёт через общий диалог подтверждения.
 */
final class CostMappingsIndexPageTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111118801';

    public function testPageExposesModuleHooksAndDeleteTriggerPerMapping(): void
    {
        $client = static::createClient();

        $owner = UserBuilder::aUser()->withEmail('cost-mappings-page@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withCostCategoryName('Логистика Ozon')
            ->build();
        foreach ([$owner, $company, $mapping] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $crawler = $client->request('GET', '/marketplace-analytics/cost-mappings?marketplace=ozon');

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-cost-mappings]');
        self::assertCount(1, $root);
        self::assertSame('/api/marketplace-analytics/cost-mappings', $root->attr('data-add-url'));
        self::assertSame('/marketplace-analytics/cost-mappings', $root->attr('data-index-url'));
        self::assertSame('ozon', $root->filter('[data-cost-mapping-marketplace] option[selected]')->attr('value'));
        self::assertCount(1, $root->filter('button[type="button"][data-cost-mapping-add]'));
        self::assertGreaterThan(0, $root->filter('[data-cost-mapping-type] option')->count());

        $trigger = $crawler->filter('[data-cost-mapping-delete]')->reduce(
            static fn ($node): bool => str_contains((string) $node->attr('data-confirm-action'), UnitEconomyCostMappingBuilder::DEFAULT_ID),
        );
        self::assertCount(1, $trigger);
        self::assertSame('#cost-mapping-confirm', $trigger->attr('data-bs-target'));
        self::assertSame('/api/marketplace-analytics/cost-mappings/'.UnitEconomyCostMappingBuilder::DEFAULT_ID, $trigger->attr('data-confirm-action'));
        self::assertSame('danger', $trigger->attr('data-confirm-variant'));
        self::assertStringContainsString('Логистика Ozon', (string) $trigger->attr('data-confirm-body'));
        self::assertCount(1, $crawler->filter('#cost-mapping-confirm[data-confirm-modal]'));
        self::assertCount(0, $crawler->filter('[onclick], [onchange]'));
    }
}
