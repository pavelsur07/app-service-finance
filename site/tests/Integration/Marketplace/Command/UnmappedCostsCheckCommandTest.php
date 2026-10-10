<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceCost;
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

final class UnmappedCostsCheckCommandTest extends IntegrationTestCase
{
    public function testReportsOnlyCostsLeftOutOfPlByMissingMapping(): void
    {
        $company = $this->company(961, true);
        $line = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Статья')->withCode('LINE')->build();
        $this->em->persist($line);

        $today = new \DateTimeImmutable('today');

        // Красное: правила нет / правило включено без статьи.
        $this->cost($company, $this->category($company, 'ozon_crossdocking'), $today, '1522.20');
        $empty = $this->category($company, 'ozon_return_from_stock');
        $this->mapping($company, $empty, null, true);
        $this->cost($company, $empty, $today, '48.00');

        // Не красное: статья назначена / осознанно исключено / вне окна /
        // нераспознанный код (его ловит сверка Ozon, маппинг его не чинит).
        $mapped = $this->category($company, 'ozon_premium_promotion');
        $this->mapping($company, $mapped, $line->getId(), true);
        $this->cost($company, $mapped, $today, '1490.00');
        $excluded = $this->category($company, 'ozon_analytics_premium');
        $this->mapping($company, $excluded, null, false);
        $this->cost($company, $excluded, $today, '8500.00');
        $this->cost($company, $this->category($company, 'ozon_service_correction'), $today->modify('first day of -3 months'), '4329.00');
        $this->cost($company, $this->category($company, 'ozon_unknown_126'), $today, '10.00');

        // Компания без активного подключения не проверяется.
        $inactive = $this->company(962, false);
        $this->cost($inactive, $this->category($inactive, 'ozon_crossdocking'), $today, '590.00');
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit, $display);
        self::assertStringContainsString('«ozon_crossdocking»: 1 строк, 1522.20', $display);
        self::assertStringContainsString('«ozon_return_from_stock»: 1 строк, 48.00', $display);
        self::assertStringContainsString('unmapped rows count: 2', $display);
        self::assertStringContainsString('affected companies count: 1', $display);
        self::assertStringNotContainsString((string) $inactive->getId(), $display);
    }

    public function testGreenWhenEveryCostHasPlDecision(): void
    {
        $company = $this->company(963, true);
        $line = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Статья')->withCode('LINE')->build();
        $this->em->persist($line);
        $category = $this->category($company, 'ozon_crossdocking');
        $this->mapping($company, $category, $line->getId(), true);
        $this->cost($company, $category, new \DateTimeImmutable('today'), '1522.20');
        $this->em->flush();

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('unmapped rows count: 0', $tester->getDisplay());
    }

    private function company(int $index, bool $activeConnection): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive($activeConnection);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);

        return $company;
    }

    private function category(Company $company, string $code): MarketplaceCostCategory
    {
        $category = (new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON))
            ->setCode($code)
            ->setName($code);
        $this->em->persist($category);

        return $category;
    }

    private function mapping(Company $company, MarketplaceCostCategory $category, ?string $plCategoryId, bool $includeInPl): void
    {
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), (string) $company->getId(), $category, $plCategoryId, $includeInPl));
    }

    private function cost(Company $company, MarketplaceCostCategory $category, \DateTimeImmutable $date, string $amount): void
    {
        $cost = (new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, $category))
            ->setAmount($amount)
            ->setCostDate($date);
        $this->em->persist($cost);
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:cost-pl-mapping:unmapped-check'));
    }
}
