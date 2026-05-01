<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\MessageHandler\TriggerInitialSyncHandler;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class TriggerInitialSyncHandlerTest extends TestCase
{
    public function testWbBuildsPartitionsFromResolvedDate(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->method('resolve')->willReturn(new \DateTimeImmutable('2026-04-01 00:00:00'));

        $captured = new \stdClass(); $captured->message = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($m) use ($captured) { $captured->message = $m; return new Envelope($m); });

        $handler = new TriggerInitialSyncHandler($bus, new NullLogger(), new MarketplaceWeekPartitionService(), new MockClock('2026-04-30 00:00:00'), $repo, $resolver, $this->createMock(EntityManagerInterface::class));
        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
        self::assertStringStartsWith('2026-04', $captured->message->dateFrom);
    }

    public function testRateLimitConvertsToRecoverableExceptionWithDelay(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->method('resolve')->willThrowException(new MarketplaceRateLimitException(429, 'rl', '2026-01-01', '2026-01-31', null));

        $handler = new TriggerInitialSyncHandler($this->createMock(MessageBusInterface::class), new NullLogger(), new MarketplaceWeekPartitionService(), new MockClock('2026-04-30 00:00:00'), $repo, $resolver, $this->createMock(EntityManagerInterface::class));

        try {
            $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));
            self::fail('Expected RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(600_000, $e->getRetryDelay());
        }
    }

    public function testRateLimitRetryAfterDelayAndNoDispatch(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->method('resolve')->willThrowException(new MarketplaceRateLimitException(429, 'rl', '2026-01-01', '2026-01-31', 2));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new TriggerInitialSyncHandler($bus, new NullLogger(), new MarketplaceWeekPartitionService(), new MockClock('2026-04-30 00:00:00'), $repo, $resolver, $this->createMock(EntityManagerInterface::class));

        try {
            $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));
            self::fail('Expected RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(2_000, $e->getRetryDelay());
        }
    }

    public function testWbNoDataMarksSyncSuccessAndSkipsDispatch(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = $this->getMockBuilder(MarketplaceConnection::class)
            ->setConstructorArgs(['22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES])
            ->onlyMethods(['markSyncSuccess'])
            ->getMock();
        $connection->expects(self::once())->method('markSyncSuccess')->willReturnSelf();

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $handler = new TriggerInitialSyncHandler($bus, new NullLogger(), new MarketplaceWeekPartitionService(), new MockClock('2026-04-30 00:00:00'), $repo, $resolver, $em);
        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));
    }

    public function testNonWbDoesNotCallResolverAndUsesYearStart(): void
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
        $bus->method('dispatch')->willReturnCallback(static function ($m) use ($captured) { $captured->message = $m; return new Envelope($m); });

        $handler = new TriggerInitialSyncHandler($bus, new NullLogger(), new MarketplaceWeekPartitionService(), new MockClock('2026-04-30 00:00:00'), $repo, $resolver, $this->createMock(EntityManagerInterface::class));
        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::OZON->value));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
        self::assertSame('2026-01-01 00:00:00', $captured->message->dateFrom);
    }
}
