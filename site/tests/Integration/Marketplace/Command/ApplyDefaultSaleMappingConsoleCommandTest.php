<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
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

final class ApplyDefaultSaleMappingConsoleCommandTest extends IntegrationTestCase
{
    public function testAllPreviewWritesNothingAndExecuteFillsEveryConnectionSkippingBlocked(): void
    {
        $empty = $this->company(951);
        $this->standardPlTree($empty, MarketplaceType::OZON);
        $this->connection($empty, MarketplaceType::OZON);

        $manual = $this->company(952);
        $plByCode = $this->standardPlTree($manual, MarketplaceType::WILDBERRIES);
        $manualTarget = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($manual)->withName('Ручная выручка')->withCode('MANUAL_REV')->build();
        $this->em->persist($manualTarget);
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $manual, MarketplaceType::WILDBERRIES, AmountSource::SALE_GROSS, $manualTarget));
        $this->connection($manual, MarketplaceType::WILDBERRIES);

        // Без дерева ОПиУ: блокируется, но остальных не останавливает.
        $noTree = $this->company(953);
        $this->connection($noTree, MarketplaceType::OZON);

        // Без SELLER-подключения: в --all не попадает.
        $noConnection = $this->company(954);
        $this->standardPlTree($noConnection, MarketplaceType::OZON);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--all' => true]));
        self::assertStringContainsString('will_create', $tester->getDisplay());
        self::assertStringContainsString('missing_pl_category', $tester->getDisplay());
        self::assertSame(1, $this->rulesCount($manual), 'предпросмотр ничего не пишет');
        self::assertSame(0, $this->rulesCount($empty));

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--all' => true, '--execute' => true]));
        self::assertStringContainsString('Пар: 3, создано правил: 11, заблокировано: 1', $tester->getDisplay());

        self::assertSame($this->ruleCount(MarketplaceType::OZON), $this->rulesCount($empty));
        self::assertSame($this->ruleCount(MarketplaceType::WILDBERRIES), $this->rulesCount($manual));
        self::assertSame($manualTarget->getId(), $this->ruleTarget($manual, AmountSource::SALE_GROSS), 'ручное правило не перезаписывается');
        self::assertSame($plByCode['REV_SPP_SALES']->getId(), $this->ruleTarget($manual, AmountSource::SALE_REVENUE));
        self::assertSame(0, $this->rulesCount($noTree));
        self::assertSame(0, $this->rulesCount($noConnection));
    }

    public function testCompanyWithExplicitMarketplaceRunsWithoutConnection(): void
    {
        $company = $this->company(955);
        $this->standardPlTree($company, MarketplaceType::OZON);
        $neighbour = $this->company(956);
        $this->standardPlTree($neighbour, MarketplaceType::OZON);
        $this->connection($neighbour, MarketplaceType::OZON);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--company-id' => $company->getId(), '--marketplace' => 'ozon', '--execute' => true]));

        self::assertSame($this->ruleCount(MarketplaceType::OZON), $this->rulesCount($company));
        self::assertSame(0, $this->rulesCount($neighbour), 'другая компания не затрагивается');
    }

    public function testRejectsInvalidArguments(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute([]));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--all' => true, '--company-id' => Uuid::uuid4()->toString()]));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--company-id' => 'not-a-uuid']));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--all' => true, '--marketplace' => 'yandex_market']));

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--company-id' => Uuid::uuid4()->toString(), '--marketplace' => 'ozon']));
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function connection(Company $company, MarketplaceType $marketplace): void
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, $marketplace);
        $connection->setApiKey('test-key');
        $this->em->persist($connection);
    }

    /**
     * Все строки ОПиУ, на которые ссылаются базовые правила маркетплейса.
     *
     * @return array<string, PLCategory>
     */
    private function standardPlTree(Company $company, MarketplaceType $marketplace): array
    {
        $categories = [];
        foreach (array_unique(array_column($this->rules($marketplace), 'pl_code')) as $code) {
            $categories[$code] = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName($code)->withCode($code)->build();
            $this->em->persist($categories[$code]);
        }

        return $categories;
    }

    /**
     * @return list<array{amount_source: string, pl_code: string}>
     */
    private function rules(MarketplaceType $marketplace): array
    {
        $config = Yaml::parseFile(__DIR__.'/../../../../config/marketplace/default_sale_mapping.yaml');

        return $config['marketplaces'][$marketplace->value]['sale_mappings'];
    }

    private function ruleCount(MarketplaceType $marketplace): int
    {
        return \count($this->rules($marketplace));
    }

    private function rulesCount(Company $company): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM marketplace_sale_mappings WHERE company_id = :id', ['id' => $company->getId()]);
    }

    private function ruleTarget(Company $company, AmountSource $source): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT pl_category_id FROM marketplace_sale_mappings WHERE company_id = :id AND amount_source = :source AND is_active = true',
            ['id' => $company->getId(), 'source' => $source->value],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:sale-mapping:apply-default'));
    }
}
