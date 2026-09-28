<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\DTO\PreflightCheck;
use App\Marketplace\Application\DTO\PreflightResult;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Preflight этапа COSTS: нераспознанные затраты и затраты без решения по ОПиУ.
 *
 * Регресс: by-day пишет неизвестную услугу Ozon в `ozon_unknown_<type_id>`, а
 * проверка искала только легаси-корзину `ozon_other_service` и была зелёной;
 * затраты без маппинга давали предупреждение и молча выпадали из ОПиУ.
 */
final class PreflightCostRecognitionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '33333333-3333-3333-3333-000000000011';
    private const OWNER_ID = '44444444-4444-4444-4444-000000000011';

    private Company $company;
    private MonthClosePreflightAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('preflight-recognition@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();

        $this->action = self::getContainer()->get(MonthClosePreflightAction::class);
    }

    public function testOzonUnknownServiceBlocksFinalCloseEvenWhenMapped(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_unknown_900', 'Неразобранная услуга Ozon: NewService');
        $this->mapping($category, plCategoryId: Uuid::uuid4()->toString());
        $this->cost($category, MarketplaceType::OZON);
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        $check = $this->check($result, 'costs_unknown_service_names');
        self::assertTrue($check->blocking);
        self::assertSame(2, $check->value);
        self::assertSame('Неразобранная услуга Ozon: NewService', $check->details[0]['service_name']);
        self::assertSame(2, $check->details[0]['count']);
        self::assertFalse($result->canClose());
    }

    /**
     * Типов нет в справочнике Ozon — названия одинаковые; детали различают
     * услуги по коду категории, а не сливают их в одну строку.
     */
    public function testOzonUnknownServicesWithSameNameAreListedSeparately(): void
    {
        $first = $this->category(MarketplaceType::OZON, 'ozon_unknown_901', 'Неразобранная услуга Ozon');
        $second = $this->category(MarketplaceType::OZON, 'ozon_unknown_902', 'Неразобранная услуга Ozon');
        $this->cost($first, MarketplaceType::OZON);
        $this->cost($first, MarketplaceType::OZON);
        $this->cost($second, MarketplaceType::OZON);
        $this->em->flush();

        $check = $this->check($this->preflight(MarketplaceType::OZON), 'costs_unknown_service_names');

        self::assertCount(2, $check->details);
        self::assertSame('ozon_unknown_901', $check->details[0]['category_code']);
        self::assertSame(2, $check->details[0]['count']);
        self::assertSame('ozon_unknown_902', $check->details[1]['category_code']);
        self::assertSame(1, $check->details[1]['count']);
    }

    public function testOzonUnknownServiceOnlyWarnsBeforePreliminaryClose(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_unknown_900', 'Неразобранная услуга Ozon: NewService');
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON, preliminary: true);

        $unknown = $this->check($result, 'costs_unknown_service_names');
        self::assertFalse($unknown->passed);
        self::assertFalse($unknown->blocking);

        // Незамапленная нераспознанная затрата показана один раз — в проверке 3.
        self::assertTrue($this->check($result, 'costs_without_mapping')->passed);

        self::assertTrue($result->canClose());
    }

    /**
     * Незамапленная нераспознанная затрата блокирует через проверку 3 и не
     * дублируется в «Маппинг затрат к ОПиУ»; распознанные без маппинга — там.
     */
    public function testUnmappedUnrecognizedCostIsNotReportedTwice(): void
    {
        $unknown = $this->category(MarketplaceType::OZON, 'ozon_unknown_900', 'Неразобранная услуга Ozon: NewService');
        $this->cost($unknown, MarketplaceType::OZON);
        $this->cost($unknown, MarketplaceType::OZON);
        $storage = $this->category(MarketplaceType::OZON, 'ozon_storage', 'Хранение Ozon');
        $this->cost($storage, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        $unrecognized = $this->check($result, 'costs_unknown_service_names');
        self::assertTrue($unrecognized->blocking);
        self::assertSame(2, $unrecognized->value);

        $withoutMapping = $this->check($result, 'costs_without_mapping');
        self::assertTrue($withoutMapping->blocking);
        self::assertSame(1, $withoutMapping->value);
        self::assertSame(['ozon_storage'], array_column($withoutMapping->details, 'category_code'));

        self::assertFalse($result->canClose());
    }

    public function testMappedOzonCatalogCostPassesBothChecks(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_logistic_direct', 'Логистика к покупателю Ozon');
        $this->mapping($category, plCategoryId: Uuid::uuid4()->toString());
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        self::assertTrue($this->check($result, 'costs_unknown_service_names')->passed);
        self::assertTrue($this->check($result, 'costs_without_mapping')->passed);
        self::assertTrue($result->canClose());
    }

    public function testCostWithoutMappingBlocksFinalClose(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_storage', 'Хранение Ozon');
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        $check = $this->check($result, 'costs_without_mapping');
        self::assertTrue($check->blocking);
        self::assertSame(1, $check->value);
        self::assertSame('ozon_storage', $check->details[0]['category_code']);
        self::assertFalse($result->canClose());
    }

    public function testMappingIncludedInPlWithoutArticleBlocksFinalClose(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_storage', 'Хранение Ozon');
        $this->mapping($category, plCategoryId: null);
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        self::assertTrue($this->check($result, 'costs_without_mapping')->blocking);
        self::assertFalse($result->canClose());
    }

    public function testExplicitExclusionFromPlIsADecision(): void
    {
        $category = $this->category(MarketplaceType::OZON, 'ozon_storage', 'Хранение Ozon');
        $this->mapping($category, plCategoryId: null, includeInPl: false);
        $this->cost($category, MarketplaceType::OZON);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::OZON);

        self::assertTrue($this->check($result, 'costs_without_mapping')->passed);
        self::assertFalse($this->check($result, 'costs_excluded')->blocking);
        self::assertTrue($result->canClose());
    }

    public function testWbUnknownDeductionWithoutMappingBlocksFinalClose(): void
    {
        $category = $this->category(MarketplaceType::WILDBERRIES, 'wb_novoe_uderzhanie', 'Новое удержание');
        $this->cost($category, MarketplaceType::WILDBERRIES);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::WILDBERRIES);

        $check = $this->check($result, 'costs_unknown_service_names');
        self::assertTrue($check->blocking);
        self::assertSame('Новое удержание', $check->details[0]['service_name']);
        self::assertFalse($check->details[0]['decided']);
        self::assertTrue($this->check($result, 'costs_without_mapping')->passed);
        self::assertFalse($result->canClose());
    }

    public function testMappedWbUnknownDeductionOnlyWarns(): void
    {
        $category = $this->category(MarketplaceType::WILDBERRIES, 'wb_novoe_uderzhanie', 'Новое удержание');
        $this->mapping($category, plCategoryId: Uuid::uuid4()->toString());
        $this->cost($category, MarketplaceType::WILDBERRIES);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::WILDBERRIES);

        $check = $this->check($result, 'costs_unknown_service_names');
        self::assertFalse($check->passed);
        self::assertFalse($check->blocking);
        self::assertTrue($check->details[0]['decided']);
        self::assertTrue($result->canClose());
    }

    public function testMarketplaceWithoutCatalogSkipsRecognitionCheck(): void
    {
        $category = $this->category(MarketplaceType::YANDEX_MARKET, 'ym_anything', 'Услуга Маркета');
        $this->mapping($category, plCategoryId: Uuid::uuid4()->toString());
        $this->cost($category, MarketplaceType::YANDEX_MARKET);
        $this->em->flush();

        $result = $this->preflight(MarketplaceType::YANDEX_MARKET);

        self::assertTrue($this->check($result, 'costs_unknown_service_names')->passed);
    }

    private function preflight(MarketplaceType $marketplace, bool $preliminary = false): PreflightResult
    {
        return ($this->action)(new PreflightMonthCloseCommand(
            companyId: self::COMPANY_ID,
            marketplace: $marketplace->value,
            year: 2026,
            month: 3,
            stage: CloseStage::COSTS,
            preliminary: $preliminary,
        ));
    }

    private function check(PreflightResult $result, string $key): PreflightCheck
    {
        foreach ($result->checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        self::fail(sprintf('Проверка %s отсутствует', $key));
    }

    private function category(MarketplaceType $marketplace, string $code, string $name): MarketplaceCostCategory
    {
        $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $this->company, $marketplace);
        $category->setCode($code);
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }

    private function mapping(MarketplaceCostCategory $category, ?string $plCategoryId, bool $includeInPl = true): void
    {
        $this->em->persist(new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $category,
            $plCategoryId,
            $includeInPl,
        ));
    }

    private function cost(MarketplaceCostCategory $category, MarketplaceType $marketplace): void
    {
        $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $this->company, $marketplace, $category);
        $cost->setAmount('100.00');
        $cost->setCostDate(new \DateTimeImmutable('2026-03-10'));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('ext-'.Uuid::uuid4()->toString());
        $this->em->persist($cost);
    }
}
