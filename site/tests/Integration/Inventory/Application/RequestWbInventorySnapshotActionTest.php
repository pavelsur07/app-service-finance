<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Application;

use App\Company\Entity\Company;
use App\Inventory\Application\RequestWbInventorySnapshotAction;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\SyncWbInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RequestWbInventorySnapshotActionTest extends IntegrationTestCase
{
    public function testNoActiveConnectionReturnsHasConnectionsFalse(): void
    {
        $action = $this->action(new WbInMemoryBus());

        $result = $action($this->createCompany(601)->getId(), SnapshotTriggerType::Manual);

        self::assertFalse($result->hasConnections);
        self::assertSame(0, $result->queuedCount);
    }

    public function testActiveConnectionCreatesSessionAndDispatchesMessage(): void
    {
        $company = $this->createCompany(602);
        $connection = $this->createConnection($company, 602);
        $bus = new WbInMemoryBus();

        $result = ($this->action($bus))($company->getId(), SnapshotTriggerType::Manual);

        self::assertSame(1, $result->queuedCount);
        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(SyncWbInventorySnapshotMessage::class, $bus->messages[0]);
        self::assertSame($connection->getId(), $bus->messages[0]->connectionId);
    }

    public function testActiveSessionSkipsDuplicateDispatch(): void
    {
        $company = $this->createCompany(603);
        $this->createConnection($company, 603);
        $bus = new WbInMemoryBus();
        $action = $this->action($bus);

        self::assertSame(1, $action($company->getId(), SnapshotTriggerType::Manual)->queuedCount);
        $second = $action($company->getId(), SnapshotTriggerType::Manual);

        self::assertTrue($second->hasActiveSession);
        self::assertSame(0, $second->queuedCount);
        self::assertCount(1, $bus->messages);
    }

    public function testDispatchFailureMarksSessionFailed(): void
    {
        $company = $this->createCompany(604);
        $this->createConnection($company, 604);

        $result = ($this->action(new WbFailingBus()))($company->getId(), SnapshotTriggerType::Manual);

        self::assertSame(0, $result->queuedCount);
        self::assertSame(1, $result->skippedCount);
        self::assertNull(self::getContainer()->get(InventorySnapshotSessionRepository::class)
            ->findLatestActiveByCompanyAndSource($company->getId(), MarketplaceType::WILDBERRIES));
    }

    private function action(MessageBusInterface $bus): RequestWbInventorySnapshotAction
    {
        return new RequestWbInventorySnapshotAction(
            self::getContainer()->get(MarketplaceFacade::class),
            self::getContainer()->get(InventorySnapshotSessionRepository::class),
            $this->em,
            $bus,
            self::getContainer()->get(AppLogger::class),
        );
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

    private function createConnection(Company $company, int $index): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            sprintf('66666666-6666-4666-8666-%012d', $index),
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-token')->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }
}

final class WbInMemoryBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message);
    }
}

final class WbFailingBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw new \RuntimeException('Dispatch failed.');
    }
}
