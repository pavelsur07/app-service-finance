<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\MessageHandler\TriggerInitialSyncHandler;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TriggerInitialSyncHandlerTest extends TestCase
{
    public function testWbBuildsPartitionsFromResolvedDateAndCapsTo60Days(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new \DateTimeImmutable('2026-01-10 00:00:00'));

        $captured = new \stdClass();
        $captured->message = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($m) use ($captured) {
            $captured->message = $m;

            return new Envelope($m);
        });

        $handler = new TriggerInitialSyncHandler(
            $bus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
            new MockClock('2026-04-30 00:00:00'),
            $repo,
            $resolver,
        );

        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
        self::assertSame('2026-01-10 00:00:00', $captured->message->dateFrom);
        self::assertSame('2026-01-19 23:59:59', $captured->message->dateTo);
        self::assertNotNull($captured->message->nextDateTo);
        self::assertLessThanOrEqual(
            new \DateTimeImmutable('2026-03-11 23:59:59'),
            new \DateTimeImmutable($captured->message->nextDateTo),
        );
    }

    public function testNonWbDoesNotCallResolverAndUsesYearStartUntilYesterday(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::OZON);
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->expects(self::never())->method('resolve');

        $captured = new \stdClass();
        $captured->message = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($m) use ($captured) {
            $captured->message = $m;

            return new Envelope($m);
        });

        $handler = new TriggerInitialSyncHandler(
            $bus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
            new MockClock('2026-04-30 00:00:00'),
            $repo,
            $resolver,
        );

        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::OZON->value));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
        self::assertSame('2026-01-01 00:00:00', $captured->message->dateFrom);
        self::assertSame('2026-01-11 23:59:59', $captured->message->dateTo);
        self::assertNotNull($captured->message->nextDateTo);
        self::assertSame('2026-04-29 23:59:59', $captured->message->nextDateTo);
    }
}
