<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchOzonAdLoadActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';

    private MarketplaceFacade $marketplaceFacade;
    private AdLoadJobRepository $adLoadJobRepository;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $messageBus;
    private DispatchOzonAdLoadAction $action;

    protected function setUp(): void
    {
        $this->marketplaceFacade   = $this->createMock(MarketplaceFacade::class);
        $this->adLoadJobRepository = $this->createMock(AdLoadJobRepository::class);
        $this->entityManager       = $this->createMock(EntityManagerInterface::class);
        $this->messageBus          = $this->createMock(MessageBusInterface::class);

        $this->action = new DispatchOzonAdLoadAction(
            $this->marketplaceFacade,
            $this->adLoadJobRepository,
            $this->entityManager,
            $this->messageBus,
        );
    }

    public function testHappyPath(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE)
            ->willReturn(['api_key' => 'secret', 'client_id' => 'client-abc']);

        $this->adLoadJobRepository
            ->method('findLatestActiveJobByCompanyAndMarketplace')
            ->willReturn(null);

        $this->adLoadJobRepository->expects(self::once())->method('save');
        $this->entityManager->expects(self::once())->method('flush');

        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(LoadOzonAdStatisticsRangeMessage::class))
            ->willReturnCallback(static fn (object $msg) => new Envelope($msg));

        $dateFrom = new \DateTimeImmutable('2026-01-01');
        $dateTo   = new \DateTimeImmutable('yesterday');

        $job = ($this->action)(self::COMPANY_ID, $dateFrom, $dateTo);

        self::assertInstanceOf(AdLoadJob::class, $job);
        self::assertSame(self::COMPANY_ID, $job->getCompanyId());
        self::assertSame(MarketplaceType::OZON, $job->getMarketplace());
    }

    public function testThrowsWhenConnectionNotConfigured(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ozon Performance connection not configured');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );
    }

    public function testThrowsWhenDateFromAfterDateTo(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Дата начала не может быть позже даты окончания');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-31'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }

    public function testThrowsWhenDateToIsInFuture(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя загружать данные за будущие даты');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('+7 days'),
        );
    }

    public function testThrowsWhenActiveJobAlreadyExists(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $existingJob = new AdLoadJob(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        $this->adLoadJobRepository
            ->method('findLatestActiveJobByCompanyAndMarketplace')
            ->willReturn($existingJob);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Load already in progress');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('yesterday'),
        );
    }
}
