<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\MessageHandler\TriggerInitialSyncHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TriggerInitialSyncHandlerTest extends IntegrationTestCase
{
    public function testWbStartDateFromPastPlansInitialDailySyncWithoutCap(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);

        $connection = new MarketplaceConnection(
            '22222222-2222-2222-2222-222222222222',
            $company,
            MarketplaceType::WILDBERRIES,
        );
        $connection->setApiKey('test-key');
        $connection->setSettings([
            'wb_initial_sync_start_date' => '2025-05-01',
        ]);
        $this->em->persist($connection);
        $this->em->flush();

        $bus = new class() implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };

        $resolver = new WbInitialSyncStartDateResolver(
            self::getContainer()->get(MarketplaceRawDocumentRepository::class),
            new MockClock('2026-05-10 00:00:00'),
        );


        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planInitial')
            ->with(
                $company->getId(),
                $connection->getId(),
                self::callback(static function (\DateTimeImmutable $date): bool {
                    return '2025-05-01 00:00:00' === $date->format('Y-m-d H:i:s');
                }),
            )
            ->willReturn(375);

        $handler = new TriggerInitialSyncHandler(
            $bus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
            new MockClock('2026-05-10 00:00:00'),
            self::getContainer()->get(\App\Marketplace\Repository\MarketplaceConnectionRepository::class),
            $resolver,
            $planner,
        );

        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));

    }
}
