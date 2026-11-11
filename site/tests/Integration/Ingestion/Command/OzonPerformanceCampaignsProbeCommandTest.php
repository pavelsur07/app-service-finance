<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceReportClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonPerformanceCampaignsProbeCommandTest extends IntegrationTestCase
{
    public function testListsAllCampaignTypesWithoutTitlesAndSpendFromStoredExpense(): void
    {
        $owner = UserBuilder::aUser()->withEmail('probe@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE);
        $connection->setApiKey('secret');
        $connection->setClientId('client');
        $connection->setIsActive(true);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();
        $companyId = (string) $company->getId();

        $client = $this->createMock(OzonPerformanceReportClientInterface::class);
        // Без фильтра типа: команда должна видеть кампании, которых Ingestion не грузит.
        $client->expects(self::once())->method('listCampaigns')->with($companyId, $connection->getId(), [])->willReturn(new OzonRawPage([
            ['id' => '11383505', 'advObjectType' => 'SEARCH_PROMO', 'state' => 'CAMPAIGN_STATE_RUNNING', 'title' => 'Секретное имя'],
            ['id' => '49903075', 'advObjectType' => 'NEW_TYPE', 'state' => 'CAMPAIGN_STATE_RUNNING', 'PaymentType' => 'CPO', 'title' => 'Секретное имя'],
        ], false, null));
        self::getContainer()->set(OzonPerformanceReportClientInterface::class, $client);

        /** @var RawStorageFacade $storage */
        $storage = self::getContainer()->get(RawStorageFacade::class);
        foreach ([['49903075', '2026-11-07', '100,00'], ['49903075', '2026-11-07', '5174,76'], ['49903075', '2026-11-06', '9,00']] as $i => [$campaign, $day, $spent]) {
            $storage->store(new RawBatch(
                companyId: $companyId,
                connectionRef: $connection->getId(),
                shopRef: $connection->getId(),
                source: IngestSource::OZON,
                resourceType: OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
                externalId: 0 === $i ? 'performance-expense-stats:2026-11-01:2026-11-07' : 'performance-expense-stats:2026-11-01:2026-11-0'.(7 + $i),
                syncJobId: Uuid::uuid7()->toString(),
                fetchedAt: new \DateTimeImmutable(),
                rows: [['id' => $campaign, 'date' => $day, 'moneySpent' => $spent]],
            ));
            usleep(2000);
        }

        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ingestion:ozon-performance:campaigns-probe'));
        $status = $tester->execute(['--company-id' => $companyId, '--from' => '2026-11-07', '--to' => '2026-11-07']);

        self::assertSame(Command::SUCCESS, $status);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('49903075 NEW_TYPE CAMPAIGN_STATE_RUNNING НЕТ PaymentType=\'CPO\'', $display);
        self::assertStringContainsString('11383505 SEARCH_PROMO CAMPAIGN_STATE_RUNNING да', $display);
        self::assertStringNotContainsString('Секретное', $display);
        // Последняя версия дня; 06.11 вне периода.
        self::assertStringContainsString('49903075 2026-11-07 moneySpent=5174.76', $display);
        self::assertStringNotContainsString('moneySpent=100.00', $display);
        self::assertStringNotContainsString('2026-11-06', $display);
    }

    public function testRejectsOnlyOneDate(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ingestion:ozon-performance:campaigns-probe'));

        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => Uuid::uuid7()->toString(), '--from' => '2026-11-07']));
    }
}
