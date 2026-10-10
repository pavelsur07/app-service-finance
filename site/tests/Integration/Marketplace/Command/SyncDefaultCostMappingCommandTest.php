<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Правила берутся из боевого шаблона config/marketplace/default_cost_mapping.yaml:
 * ozon_delivery_to_handover_place → COGS_DELIVERY, ozon_crossdocking → OPEX_WH_RECEIVING,
 * ozon_return_from_stock → COGS_RETURNS_DELIVERY.
 */
final class SyncDefaultCostMappingCommandTest extends IntegrationTestCase
{
    public function testAppliesAvailableRulesAndLeavesTheRestWithoutBlockingTheCompany(): void
    {
        $company = $this->company(951);
        $this->connection($company, MarketplaceType::OZON, true);
        $delivery = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Логистика')->withCode('COGS_DELIVERY')->build();
        $manual = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Ручная')->withCode('MANUAL_LINE')->build();
        $this->em->persist($delivery);
        $this->em->persist($manual);

        $handover = $this->costCategory($company, 'ozon_delivery_to_handover_place');
        $crossdocking = $this->costCategory($company, 'ozon_crossdocking');
        $returnFromStock = $this->costCategory($company, 'ozon_return_from_stock');
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $returnFromStock, $manual->getId()));
        // Осознанно исключено из ОПиУ, статья шаблона у компании есть.
        $pickup = $this->costCategory($company, 'ozon_logistic_pickup');
        $disabled = new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $pickup, null, false);
        $this->em->persist($disabled);
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame($delivery->getId(), $this->plCategoryOf($handover));
        self::assertNull($this->plCategoryOf($crossdocking), 'статьи OPEX_WH_RECEIVING у компании нет — правило пропущено');
        self::assertSame($manual->getId(), $this->plCategoryOf($returnFromStock), 'ручное правило не перезаписывается');
        self::assertNull($this->plCategoryOf($pickup), 'отключённое правило не заполняется');
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT include_in_pl FROM marketplace_cost_pl_mappings WHERE id = :id', ['id' => $disabled->getId()]));
        self::assertStringContainsString('ozon_delivery_to_handover_place', $tester->getDisplay());

        // Повторный прогон ничего не меняет.
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('ozon_delivery_to_handover_place', $tester->getDisplay());
    }

    public function testSkipsCompaniesWithoutActiveSellerConnection(): void
    {
        $company = $this->company(952);
        $this->connection($company, MarketplaceType::OZON, false);
        $delivery = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Логистика')->withCode('COGS_DELIVERY')->build();
        $this->em->persist($delivery);
        $handover = $this->costCategory($company, 'ozon_delivery_to_handover_place');
        $this->em->flush();

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));
        self::assertNull($this->plCategoryOf($handover));
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function connection(Company $company, MarketplaceType $marketplace, bool $active): void
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, $marketplace, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive($active);
        $this->em->persist($connection);
    }

    private function costCategory(Company $company, string $code): MarketplaceCostCategory
    {
        $category = (new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON))
            ->setCode($code)
            ->setName($code);
        $this->em->persist($category);

        return $category;
    }

    private function plCategoryOf(MarketplaceCostCategory $category): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE cost_category_id = :id',
            ['id' => $category->getId()],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:cost-pl-mapping:sync-default'));
    }
}
