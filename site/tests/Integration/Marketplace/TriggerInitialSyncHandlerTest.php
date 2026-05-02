<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\MessageHandler\TriggerInitialSyncHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TriggerInitialSyncHandlerTest extends IntegrationTestCase
{
    public function testWbStartDateFromPastIsCappedToSixtyDaysAndFirstBatchIsDispatched(): void
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

        $capturedMessage = null;
        $bus = new class($capturedMessage) implements MessageBusInterface {
            public ?object $capturedMessage = null;

            public function __construct(?object &$capturedMessage)
            {
                $this->capturedMessage = $capturedMessage;
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->capturedMessage = $message;

                return new Envelope($message, $stamps);
            }
        };

        $resolver = new WbInitialSyncStartDateResolver(
            self::getContainer()->get(MarketplaceRawDocumentRepository::class),
            new MockClock('2026-05-10 00:00:00'),
        );

        $handler = new TriggerInitialSyncHandler(
            $bus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
            new MockClock('2026-05-10 00:00:00'),
            self::getContainer()->get(\App\Marketplace\Repository\MarketplaceConnectionRepository::class),
            $resolver,
        );

        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));

        Assert::assertInstanceOf(InitialSyncMessage::class, $bus->capturedMessage);
        Assert::assertSame('2025-05-01 00:00:00', $bus->capturedMessage->dateFrom);

        $start = new \DateTimeImmutable($bus->capturedMessage->dateFrom);
        $allowedMaxEnd = $start->modify('+60 days')->setTime(23, 59, 59);
        $actualEnd = new \DateTimeImmutable($bus->capturedMessage->nextDateTo ?? $bus->capturedMessage->dateTo);

        Assert::assertLessThanOrEqual($allowedMaxEnd, $actualEnd);
    }
}
