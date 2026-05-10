<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Application;

use App\Company\Entity\Company;
use App\Inventory\Application\RequestOzonInventorySnapshotAction;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\SyncOzonInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RequestOzonInventorySnapshotActionTest extends IntegrationTestCase
{
    private InventorySnapshotSessionRepository $sessionRepository;
    private MarketplaceFacade $marketplaceFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionRepository = self::getContainer()->get(InventorySnapshotSessionRepository::class);
        $this->marketplaceFacade = self::getContainer()->get(MarketplaceFacade::class);
    }

    public function testNoActiveConnectionReturnsHasConnectionsFalse(): void
    {
        $company = $this->createCompany(101);

        $action = new RequestOzonInventorySnapshotAction(
            $this->marketplaceFacade,
            $this->sessionRepository,
            $this->em,
            new InMemoryBus(),
            self::getContainer()->get(AppLogger::class),
        );

        $result = $action($company->getId(), SnapshotTriggerType::Manual);

        self::assertFalse($result->hasConnections);
        self::assertSame(0, $result->queuedCount);
    }

    public function testActiveConnectionCreatesSessionAndDispatchesMessage(): void
    {
        $company = $this->createCompany(102);
        $this->createSellerConnection($company, '77777777-7777-7777-7777-000000000001');

        $bus = new InMemoryBus();
        $action = new RequestOzonInventorySnapshotAction(
            $this->marketplaceFacade,
            $this->sessionRepository,
            $this->em,
            $bus,
            self::getContainer()->get(AppLogger::class),
        );

        $result = $action($company->getId(), SnapshotTriggerType::Manual);

        self::assertSame(1, $result->queuedCount);
        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(SyncOzonInventorySnapshotMessage::class, $message);
        self::assertSame(SnapshotTriggerType::Manual->value, $message->triggerType);
    }

    public function testActiveSessionSkipsDuplicateDispatch(): void
    {
        $company = $this->createCompany(103);
        $this->createSellerConnection($company, '77777777-7777-7777-7777-000000000002');

        $bus = new InMemoryBus();
        $action = new RequestOzonInventorySnapshotAction(
            $this->marketplaceFacade,
            $this->sessionRepository,
            $this->em,
            $bus,
            self::getContainer()->get(AppLogger::class),
        );

        self::assertSame(1, $action($company->getId(), SnapshotTriggerType::Manual)->queuedCount);
        $second = $action($company->getId(), SnapshotTriggerType::Manual);

        self::assertTrue($second->hasActiveSession);
        self::assertSame(0, $second->queuedCount);
        self::assertCount(1, $bus->messages);
    }

    public function testAllDispatchFailuresMarkSessionFailed(): void
    {
        $company = $this->createCompany(104);
        $this->createSellerConnection($company, '77777777-7777-7777-7777-000000000003');

        $action = new RequestOzonInventorySnapshotAction(
            $this->marketplaceFacade,
            $this->sessionRepository,
            $this->em,
            new FailingBus(),
            self::getContainer()->get(AppLogger::class),
        );

        $result = $action($company->getId(), SnapshotTriggerType::Manual);

        self::assertSame(0, $result->queuedCount);
        self::assertSame(1, $result->skippedCount);

        $session = $this->sessionRepository->findLatestActiveByCompanyAndSource($company->getId(), MarketplaceType::OZON);
        self::assertNull($session, 'Session must not remain active pending/in_progress when all dispatches failed.');
    }

    public function testInvalidConnectionIdScenarioIsNotReachableBecauseMarketplaceConnectionIdMustBeUuid(): void
    {
        $company = $this->createCompany(105);

        $this->expectException(\InvalidArgumentException::class);
        // Intentional invariant check:
        // MarketplaceConnection validates UUID in constructor (Assert::uuid),
        // therefore integration scenario with invalid connectionId from MarketplaceFacade
        // is not reachable without breaking Entity/DB constraints.
        new MarketplaceConnection('invalid-connection-id', $company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
    }

    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function createSellerConnection(Company $company, string $connectionId): void
    {
        $connection = new MarketplaceConnection($connectionId, $company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();
    }
}

final class InMemoryBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}

final class FailingBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw new \RuntimeException('Dispatch failed.');
    }
}
