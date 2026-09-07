<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Company\Entity\Company;
use App\Inventory\Enum\StockSnapshotMappingStatus;
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
 * Гейт расхождений «остаток есть, карточки в каталоге нет».
 *
 * Ключевое требование: он обязан быть достижимо зелёным. Давняя непривязанная
 * позиция, для которой ещё нет операции разбора, красным его делать не должна —
 * иначе гейт вечно красный и бесполезен.
 */
final class UnmappedStockCheckCommandTest extends IntegrationTestCase
{
    public function testPassesWhenThereAreNoActiveConnections(): void
    {
        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('active connections count: 0', $tester->getDisplay());
    }

    public function testOldUnmappedVariantDoesNotFailTheGate(): void
    {
        // Реальный случай PROD: chrtId 461725762 висит непривязанным с 13.07.
        // Разобрать его автоматически нечем, поэтому красным гейт делать нельзя.
        $company = $this->seedCompanyWithConnection('old-unmapped@example.test', '77777777-7777-7777-7777-000000001001');
        $this->seedUnmapped($company, 'variant-old', 40);
        $this->seedUnmapped($company, 'variant-old', 39);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 0', $tester->getDisplay());
        self::assertStringContainsString('standing unmapped variants count: 1 / rows: 2', $tester->getDisplay());
        self::assertStringContainsString('OK company '.$company->getId(), $tester->getDisplay());
    }

    public function testNewlyAppearedUnmappedVariantFailsTheGate(): void
    {
        $company = $this->seedCompanyWithConnection('new-unmapped@example.test', '77777777-7777-7777-7777-000000001002');
        $this->seedUnmapped($company, 'variant-new', 0);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 1', $tester->getDisplay());
        self::assertStringContainsString('NEW company '.$company->getId(), $tester->getDisplay());
    }

    public function testOldVariantDoesNotMaskANewOne(): void
    {
        // Обе величины считаются раздельно: накопленный остаток не должен ни
        // прятать новое расхождение, ни подменять его собой.
        $company = $this->seedCompanyWithConnection('mixed-unmapped@example.test', '77777777-7777-7777-7777-000000001003');
        $this->seedUnmapped($company, 'variant-old', 40);
        $this->seedUnmapped($company, 'variant-fresh', 0);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 1', $tester->getDisplay());
        self::assertStringContainsString('standing unmapped variants count: 2', $tester->getDisplay());
    }

    public function testMappedRowsAreNotCountedAtAll(): void
    {
        $company = $this->seedCompanyWithConnection('mapped-only@example.test', '77777777-7777-7777-7777-000000001004');
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotDate(new \DateTimeImmutable('today'))
                ->withSnapshotAt(new \DateTimeImmutable('today'))
                ->withSource(MarketplaceType::WILDBERRIES)
                ->withListingId('55555555-5555-5555-5555-000000001001')
                ->withProductId(null)
                ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
                ->withStatus(StockStatus::Available)
                ->withSourceSku('variant-mapped')
                ->build(),
        );
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 0', $tester->getDisplay());
        self::assertStringContainsString('standing unmapped variants count: 0', $tester->getDisplay());
    }

    public function testAmbiguousCountsAsUnmapped(): void
    {
        // «Не привязана» — это любой статус, кроме mapped, а не только unmapped.
        $company = $this->seedCompanyWithConnection('ambiguous@example.test', '77777777-7777-7777-7777-000000001005');
        $this->seedUnmapped($company, 'variant-ambiguous', 0, StockSnapshotMappingStatus::Ambiguous);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 1', $tester->getDisplay());
    }

    public function testWindowSizeIsConfigurable(): void
    {
        $company = $this->seedCompanyWithConnection('window@example.test', '77777777-7777-7777-7777-000000001006');
        $this->seedUnmapped($company, 'variant-week-old', 7);

        self::assertSame(Command::SUCCESS, $this->runCommand(['--window-days' => '2'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runCommand(['--window-days' => '10'])->getStatusCode());
    }

    public function testResolvedAndBrokenAgainVariantIsReportedAsNew(): void
    {
        // Вариант был непривязан, затем разобран, затем снова выпал из каталога.
        // По общему минимуму за всю историю такое повторное расхождение получило бы
        // старую дату и прошло бы молча — считать нужно текущий эпизод.
        $company = $this->seedCompanyWithConnection('again@example.test', '77777777-7777-7777-7777-000000001007');
        $this->seedUnmapped($company, 'variant-again', 20);
        $this->seedMapped($company, 'variant-again', 10);
        $this->seedUnmapped($company, 'variant-again', 0);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('new unmapped variants count: 1', $tester->getDisplay());
        // Давняя строка до разбора в текущий эпизод не входит.
        self::assertStringContainsString('standing unmapped variants count: 1 / rows: 1', $tester->getDisplay());
    }

    public function testZeroWindowCountsOnlyTodaysAppearance(): void
    {
        $company = $this->seedCompanyWithConnection('zero-window@example.test', '77777777-7777-7777-7777-000000001008');
        $this->seedUnmapped($company, 'variant-today', 0);

        self::assertSame(Command::FAILURE, $this->runCommand(['--window-days' => '0'])->getStatusCode());
    }

    public function testWindowLowerBoundIsInclusive(): void
    {
        $company = $this->seedCompanyWithConnection('boundary@example.test', '77777777-7777-7777-7777-000000001009');
        $this->seedUnmapped($company, 'variant-on-boundary', 2);

        // Ровно на границе окна — считается новым.
        self::assertSame(Command::FAILURE, $this->runCommand(['--window-days' => '2'])->getStatusCode());
        // На день раньше границы — уже нет.
        self::assertSame(Command::SUCCESS, $this->runCommand(['--window-days' => '1'])->getStatusCode());
    }

    public function testOversizedWindowIsRejected(): void
    {
        // Строка из цифр произвольной длины насыщалась бы до PHP_INT_MAX и ломала
        // арифметику дат, превращая гейт в ложный failure.
        $tester = $this->runCommand(['--window-days' => '99999999999999999999']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('between 0 and', $tester->getDisplay());
    }

    public function testNegativeWindowIsRejected(): void
    {
        self::assertSame(Command::INVALID, $this->runCommand(['--window-days' => '-1'])->getStatusCode());
    }

    public function testInvalidWindowIsRejected(): void
    {
        $tester = $this->runCommand(['--window-days' => 'неделя']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('invalid input', $tester->getDisplay());
    }

    private function seedUnmapped(
        Company $company,
        string $sourceSku,
        int $daysAgo,
        StockSnapshotMappingStatus $status = StockSnapshotMappingStatus::Unmapped,
    ): void {
        $date = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $daysAgo));

        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotDate($date)
                ->withSnapshotAt($date)
                ->withSource(MarketplaceType::WILDBERRIES)
                ->withListingId(null)
                ->withProductId(null)
                ->withMappingStatus($status)
                ->withStatus(StockStatus::Available)
                ->withSourceSku($sourceSku)
                ->build(),
        );
        $this->em->flush();
    }

    private function seedMapped(Company $company, string $sourceSku, int $daysAgo): void
    {
        $date = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $daysAgo));

        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotDate($date)
                ->withSnapshotAt($date)
                ->withSource(MarketplaceType::WILDBERRIES)
                ->withListingId('55555555-5555-5555-5555-000000001002')
                ->withProductId(null)
                ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
                ->withStatus(StockStatus::Available)
                ->withSourceSku($sourceSku)
                ->build(),
        );
        $this->em->flush();
    }

    private function seedCompanyWithConnection(string $email, string $connectionId): Company
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        $connection = new MarketplaceConnection(
            id: $connectionId,
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();

        return $company;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $app = new Application(self::bootKernel());
        $tester = new CommandTester($app->find('app:inventory:unmapped-stock-check'));
        $tester->execute($options);

        return $tester;
    }
}
