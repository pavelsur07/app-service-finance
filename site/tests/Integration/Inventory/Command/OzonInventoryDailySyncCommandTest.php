<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Company\Entity\Company;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonInventoryDailySyncCommandTest extends IntegrationTestCase
{
    public function testNoConnectionsReturnsSuccess(): void
    {
        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('start', $tester->getDisplay());
        self::assertStringContainsString('active connections count: 0 / queued count: 0', $tester->getDisplay());
        self::assertStringContainsString('skipped count: 0', $tester->getDisplay());
        self::assertStringContainsString('errors count: 0', $tester->getDisplay());
        self::assertStringContainsString('finish', $tester->getDisplay());
    }

    public function testCreatesScheduledNightSessionForActiveSellerConnection(): void
    {
        $company = $this->seedCompany('owner-inv@example.test');
        $connection = new MarketplaceConnection(
            id: '77777777-7777-7777-7777-000000000010',
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 1 / queued count: 1', $tester->getDisplay());

        $session = $this->em->getRepository(InventorySnapshotSession::class)->findOneBy([
            'companyId' => $company->getId(),
        ]);

        self::assertInstanceOf(InventorySnapshotSession::class, $session);
        self::assertSame(SnapshotTriggerType::ScheduledNight, $session->getTriggerType());
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

    private function makeTester(): CommandTester
    {
        $app = new Application(self::bootKernel());
        $command = $app->find('app:inventory:ozon-daily-sync');

        return new CommandTester($command);
    }
}
