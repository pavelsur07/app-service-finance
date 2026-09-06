<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Company\Entity\Company;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Гейт свежести остатков проверяется на реальном контейнере: подключения читаются
 * настоящим MarketplaceFacade, даты снапшотов — настоящим запросом. Моки здесь были
 * бы не только лишними, но и вредными: обе зависимости — final readonly, и их дубли
 * не проходят статический анализ.
 */
final class StockFreshnessCheckCommandTest extends IntegrationTestCase
{
    public function testPassesWhenThereAreNoActiveConnections(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('active connections count: 0', $tester->getDisplay());
    }

    public function testPassesWhenActiveConnectionHasFreshSnapshot(): void
    {
        $company = $this->seedCompanyWithOzonConnection('fresh-gate@example.test', '77777777-7777-7777-7777-000000000020');
        $this->seedSnapshot($company, MarketplaceType::OZON, new \DateTimeImmutable('today'));

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('OK company '.$company->getId(), $tester->getDisplay());
        self::assertStringContainsString('stale count: 0', $tester->getDisplay());
        self::assertStringContainsString('missing count: 0', $tester->getDisplay());
    }

    public function testFailsWhenActiveConnectionSnapshotIsStale(): void
    {
        $company = $this->seedCompanyWithOzonConnection('stale-gate@example.test', '77777777-7777-7777-7777-000000000021');
        $this->seedSnapshot($company, MarketplaceType::OZON, new \DateTimeImmutable('today -3 days'));

        $tester = $this->makeTester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('STALE company '.$company->getId(), $tester->getDisplay());
        self::assertStringContainsString('stale count: 1', $tester->getDisplay());
    }

    public function testFailsWhenActiveConnectionHasNoSnapshotAtAll(): void
    {
        $company = $this->seedCompanyWithOzonConnection('missing-gate@example.test', '77777777-7777-7777-7777-000000000022');

        $tester = $this->makeTester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('MISSING company '.$company->getId(), $tester->getDisplay());
        self::assertStringContainsString('missing count: 1', $tester->getDisplay());
    }

    public function testFutureDatedSnapshotDoesNotCountAsFresh(): void
    {
        // Проверка свежести ограничивает возраст только снизу, поэтому снапшот из
        // будущего прошёл бы её и дал зелёный гейт при отсутствии актуальных данных.
        // Верхняя граница выборки совпадает с границей отчётного запроса.
        $company = $this->seedCompanyWithOzonConnection('future-gate@example.test', '77777777-7777-7777-7777-000000000023');
        $this->seedSnapshot($company, MarketplaceType::OZON, new \DateTimeImmutable('today +5 days'));

        $tester = $this->makeTester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('MISSING company '.$company->getId(), $tester->getDisplay());
        self::assertStringContainsString('missing count: 1', $tester->getDisplay());
    }

    public function testCompanyWithoutActiveConnectionIsNotChecked(): void
    {
        // Охват проверки равен охвату починки: у компании без активного подключения
        // загрузка не запускается, чинить нечего, и её протухший снапшот не имеет
        // права делать гейт красным.
        $company = $this->seedCompany('no-connection-gate@example.test');
        $this->seedSnapshot($company, MarketplaceType::OZON, new \DateTimeImmutable('today -106 days'));

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('active connections count: 0', $tester->getDisplay());
    }

    private function seedCompanyWithOzonConnection(string $email, string $connectionId): Company
    {
        $company = $this->seedCompany($email);

        $connection = new MarketplaceConnection(
            id: $connectionId,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $company;
    }

    private function seedCompany(string $email): Company
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function seedSnapshot(Company $company, MarketplaceType $source, \DateTimeImmutable $snapshotDate): void
    {
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotDate($snapshotDate)
                ->withSnapshotAt($snapshotDate)
                ->withSource($source)
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withQuantity('5.000')
                ->build(),
        );
        $this->em->flush();
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::bootKernel());

        return new CommandTester($app->find('app:inventory:stock-freshness-check'));
    }
}
