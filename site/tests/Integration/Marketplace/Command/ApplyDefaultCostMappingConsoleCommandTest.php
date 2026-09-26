<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class ApplyDefaultCostMappingConsoleCommandTest extends IntegrationTestCase
{
    public function testPreviewWritesNothingAndExecuteFillsOnlyMissingRules(): void
    {
        $company = $this->company(941);
        $plByCode = $this->standardPlTree($company);
        $manualTarget = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Ручная строка')->withCode('MANUAL_LINE')->build();
        $this->em->persist($manualTarget);

        $commission = $this->costCategory($company, 'commission');
        $acquiring = $this->costCategory($company, 'acquiring');
        $storage = $this->costCategory($company, 'storage');
        $emptyMapping = new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $acquiring, null);
        $manualMapping = new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $storage, $manualTarget->getId());
        $this->em->persist($emptyMapping);
        $this->em->persist($manualMapping);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--company-id' => $company->getId(), '--marketplace' => 'wildberries']));
        self::assertStringContainsString('will_create', $tester->getDisplay());
        self::assertNull($this->plCategoryOf($commission));
        self::assertNull($this->plCategoryOf($acquiring));

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--company-id' => $company->getId(), '--marketplace' => 'wildberries', '--execute' => true]));

        self::assertSame($plByCode['COGS_MP_COMMISSION']->getId(), $this->plCategoryOf($commission));
        self::assertSame($plByCode['COGS_ACQUIRING']->getId(), $this->plCategoryOf($acquiring));
        self::assertSame($manualTarget->getId(), $this->plCategoryOf($storage), 'ручное правило не перезаписывается');
    }

    public function testMissingPlCategoryBlocksAndWritesNothing(): void
    {
        $company = $this->company(942);
        $commission = $this->costCategory($company, 'commission');
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $company->getId(), '--marketplace' => 'wildberries', '--execute' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('missing_pl_category', $tester->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM marketplace_cost_pl_mappings WHERE cost_category_id = :id',
            ['id' => $commission->getId()],
        ));
    }

    public function testRejectsInvalidArguments(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--company-id' => 'not-a-uuid', '--marketplace' => 'ozon']));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--company-id' => Uuid::uuid4()->toString(), '--marketplace' => 'yandex_market']));
        self::assertSame(Command::FAILURE, $this->tester()->execute(['--company-id' => Uuid::uuid4()->toString(), '--marketplace' => 'ozon']));
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    /**
     * Все строки ОПиУ, на которые ссылаются правила WB в базовом маппинге.
     *
     * @return array<string, PLCategory>
     */
    private function standardPlTree(Company $company): array
    {
        $rules = Yaml::parseFile(__DIR__.'/../../../../config/marketplace/default_cost_mapping.yaml');
        $codes = array_unique(array_column($rules['marketplaces']['wildberries']['cost_mappings'], 'pl_code'));

        $categories = [];
        foreach ($codes as $code) {
            $categories[$code] = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName($code)->withCode($code)->build();
            $this->em->persist($categories[$code]);
        }

        return $categories;
    }

    private function costCategory(Company $company, string $code): MarketplaceCostCategory
    {
        $category = (new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES))
            ->setCode($code)
            ->setName($code);
        $this->em->persist($category);

        return $category;
    }

    private function plCategoryOf(MarketplaceCostCategory $category): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE cost_category_id = :id',
            ['id' => $category->getId()],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:cost-pl-mapping:apply-default'));
    }
}
