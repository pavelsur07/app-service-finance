<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Command\RepairPlMapping19621cffCommand as Repair;
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

final class RepairPlMapping19621cffCommandTest extends IntegrationTestCase
{
    private const string NOT_SPP = '883e8f5a-316a-480b-875c-402110d1a890';
    private const string WITH_SPP = 'c9bf9a27-12b2-44a4-88e4-3c98876c6b85';
    private const string NOT_SPP_SALES = '83ad7980-8ed1-4f2e-b994-8527437c8292';
    private const string SPP_SALES = 'f580a3c1-342b-449d-9d2c-007be9f9858f';
    private const string SPP_RETURNS = '63411175-935c-4956-ba2b-38c649314e24';
    private const string RETURNS_LOGISTICS = '762b6a79-4879-4d6b-a24a-4ea778eded3d';
    private const string COGS_PRODUCT = '55d59732-4f8f-4c0b-a49f-0f641f97e32c';
    private const string WB_SALE_GROSS = '95c8e1a4-d252-462c-9604-af5b27f2c8f9';
    private const string WB_SALE_REVENUE = '077b53db-0ac7-4034-b6c4-d2d927e3d5a9';

    public function testPreviewRollsBackAndExecuteRepairsOnce(): void
    {
        $this->companyTree();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('изменений 9', $tester->getDisplay());
        self::assertNull($this->code(self::SPP_SALES), 'предпросмотр откатывается');
        self::assertSame(self::NOT_SPP, $this->target(self::WB_SALE_GROSS));
        self::assertSame(4, $this->rulesCount());

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));

        self::assertSame('REV_SPP_SALES', $this->code(self::SPP_SALES));
        self::assertSame('REV_SPP_RETURNS', $this->code(self::SPP_RETURNS));
        self::assertSame('COGS_RETURNS_DELIVERY', $this->code(self::RETURNS_LOGISTICS));
        self::assertSame(self::NOT_SPP_SALES, $this->target(self::WB_SALE_GROSS));
        self::assertSame(self::SPP_SALES, $this->target(self::WB_SALE_REVENUE));
        self::assertSame(8, $this->rulesCount());
        foreach (['ozon', 'wildberries'] as $marketplace) {
            self::assertSame([self::SPP_RETURNS, true], $this->rule($marketplace, AmountSource::RETURN_REFUND));
            self::assertSame([self::COGS_PRODUCT, true], $this->rule($marketplace, AmountSource::RETURN_COST_PRICE));
        }

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('Записано изменений: 0', $tester->getDisplay());
        self::assertSame(8, $this->rulesCount());
    }

    public function testRuleChangedSinceReconciliationAbortsEverything(): void
    {
        $company = $this->companyTree();
        $this->connection->executeStatement(
            'UPDATE marketplace_sale_mappings SET pl_category_id = :to WHERE id = :id',
            ['to' => self::COGS_PRODUCT, 'id' => self::WB_SALE_REVENUE],
        );
        // Код уже занят — тоже отказ, а не тихий пропуск.
        $this->em->persist(PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Чужая')->withCode('COGS_RETURNS_DELIVERY')->build());
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('Ошибок: 2', $tester->getDisplay());

        self::assertNull($this->code(self::SPP_SALES), 'откат целиком');
        self::assertSame(self::NOT_SPP, $this->target(self::WB_SALE_GROSS));
        self::assertSame(4, $this->rulesCount());
    }

    private function companyTree(): Company
    {
        $owner = UserBuilder::aUser()->withIndex(961)->build();
        $company = CompanyBuilder::aCompany()->withIndex(961)->withId(Repair::COMPANY_ID)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        $line = function (string $id, string $name, ?string $code, PLCategoryType $type, PLFlow $flow, ?PLCategory $parent = null) use ($company): PLCategory {
            $category = PLCategoryBuilder::aPLCategory()->withId($id)->forCompany($company)->withName($name)->withCode($code)
                ->withType($type)->withFlow($flow)->withParent($parent)->build();
            $this->em->persist($category);

            return $category;
        };
        $notSpp = $line(self::NOT_SPP, 'Выкупы без СПП', 'REV_NOT_SPP', PLCategoryType::SUBTOTAL, PLFlow::INCOME);
        $withSpp = $line(self::WITH_SPP, 'Выручка с СПП', 'REV_WITH_SPP', PLCategoryType::SUBTOTAL, PLFlow::INCOME);
        $notSppSales = $line(self::NOT_SPP_SALES, 'Продажи без СПП', 'REV_NOT_SPP_SALES', PLCategoryType::LEAF_INPUT, PLFlow::INCOME, $notSpp);
        $sppSales = $line(self::SPP_SALES, 'Продажи с СПП', null, PLCategoryType::LEAF_INPUT, PLFlow::NONE, $withSpp);
        $line(self::SPP_RETURNS, 'Возвраты с СПП (-)', null, PLCategoryType::LEAF_INPUT, PLFlow::NONE, $withSpp);
        $line(self::RETURNS_LOGISTICS, 'Логистика возвратов', null, PLCategoryType::LEAF_INPUT, PLFlow::EXPENSE);
        $line(self::COGS_PRODUCT, 'Себестоимость товара', 'COGS_PRODUCT', PLCategoryType::LEAF_INPUT, PLFlow::EXPENSE);

        $this->em->persist(new MarketplaceSaleMapping(self::WB_SALE_GROSS, $company, MarketplaceType::WILDBERRIES, AmountSource::SALE_GROSS, $notSpp));
        $this->em->persist(new MarketplaceSaleMapping(self::WB_SALE_REVENUE, $company, MarketplaceType::WILDBERRIES, AmountSource::SALE_REVENUE, $withSpp));
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, AmountSource::SALE_GROSS, $notSppSales));
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, AmountSource::SALE_REVENUE, $sppSales));
        $this->em->flush();

        return $company;
    }

    private function code(string $categoryId): ?string
    {
        $value = $this->connection->fetchOne('SELECT code FROM pl_categories WHERE id = :id', ['id' => $categoryId]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function target(string $mappingId): string
    {
        return (string) $this->connection->fetchOne('SELECT pl_category_id FROM marketplace_sale_mappings WHERE id = :id', ['id' => $mappingId]);
    }

    private function rulesCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM marketplace_sale_mappings WHERE company_id = :id', ['id' => Repair::COMPANY_ID]);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function rule(string $marketplace, AmountSource $source): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pl_category_id, is_negative FROM marketplace_sale_mappings WHERE company_id = :c AND marketplace = :m AND amount_source = :s AND is_active = true',
            ['c' => Repair::COMPANY_ID, 'm' => $marketplace, 's' => $source->value],
        );
        self::assertIsArray($row);

        return [(string) $row['pl_category_id'], (bool) $row['is_negative']];
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:repair-pl-mapping-19621cff'));
    }
}
