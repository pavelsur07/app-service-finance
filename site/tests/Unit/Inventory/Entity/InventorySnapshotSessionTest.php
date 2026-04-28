<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Entity;

use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class InventorySnapshotSessionTest extends TestCase
{
    public function testConstructorGeneratesUuidV7Id(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        self::assertTrue(Uuid::isValid($session->getId()));
        self::assertSame(7, Uuid::fromString($session->getId())->getFields()->getVersion());
    }

    public function testCorrelationIdIsGeneratedWhenNotProvided(): void
    {
        $session = new InventorySnapshotSession(
            companyId: InventorySnapshotSessionBuilder::DEFAULT_COMPANY_ID,
            source: MarketplaceType::OZON,
            triggerType: SnapshotTriggerType::ScheduledNight,
            correlationId: null,
            triggeredBy: null,
            expectedPages: null,
        );

        self::assertTrue(Uuid::isValid($session->getCorrelationId()));
        self::assertSame(7, Uuid::fromString($session->getCorrelationId())->getFields()->getVersion());
    }

    public function testCorrelationIdIsStoredWhenProvided(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCorrelationId(InventorySnapshotSessionBuilder::DEFAULT_CORRELATION_ID)
            ->build();

        self::assertSame(InventorySnapshotSessionBuilder::DEFAULT_CORRELATION_ID, $session->getCorrelationId());
    }

    public function testDefaultsAreInitialized(): void
    {
        $session = new InventorySnapshotSession(
            companyId: InventorySnapshotSessionBuilder::DEFAULT_COMPANY_ID,
            source: MarketplaceType::WILDBERRIES,
            triggerType: SnapshotTriggerType::Manual,
            correlationId: null,
            triggeredBy: null,
            expectedPages: null,
        );

        self::assertSame(SnapshotSessionStatus::Pending, $session->getStatus());
        self::assertSame(0, $session->getReceivedPages());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getStartedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getUpdatedAt());
        self::assertNull($session->getTriggeredBy());
        self::assertNull($session->getExpectedPages());
    }

    public function testMarkInProgressChangesStatus(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->markInProgress();

        self::assertSame(SnapshotSessionStatus::InProgress, $session->getStatus());
    }


    public function testMarkInProgressThrowsForTerminalStatus(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();
        $session->markCompleted();

        $this->expectException(\DomainException::class);

        $session->markInProgress();
    }

    public function testIncrementReceivedPagesIncreasesCounter(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->incrementReceivedPages();
        $session->incrementReceivedPages(2);

        self::assertSame(3, $session->getReceivedPages());
    }

    public function testSetReceivedPagesSetsCounter(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->setReceivedPages(7);

        self::assertSame(7, $session->getReceivedPages());
    }

    public function testSetExpectedPagesSetsExpectedPages(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->withExpectedPages(null)->build();

        $session->setExpectedPages(15);

        self::assertSame(15, $session->getExpectedPages());
    }

    public function testMarkCompletedSetsStatusAndCompletedAt(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->markCompleted();

        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getCompletedAt());
    }

    public function testMarkPartialSetsStatusErrorMessageAndCompletedAt(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->markPartial('Some pages are missing');

        self::assertSame(SnapshotSessionStatus::Partial, $session->getStatus());
        self::assertSame('Some pages are missing', $session->getErrorMessage());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getCompletedAt());
    }

    public function testMarkFailedSetsStatusErrorMessageAndCompletedAt(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $session->markFailed('Fatal API error');

        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertSame('Fatal API error', $session->getErrorMessage());
        self::assertInstanceOf(\DateTimeImmutable::class, $session->getCompletedAt());
    }

    public function testEntityHasNoPublicSettersForImmutableFields(): void
    {
        self::assertFalse(method_exists(InventorySnapshotSession::class, 'setCompanyId'));
        self::assertFalse(method_exists(InventorySnapshotSession::class, 'setSource'));
        self::assertFalse(method_exists(InventorySnapshotSession::class, 'setTriggerType'));
    }

    public function testConstructorThrowsWhenExpectedPagesIsNegative(): void
    {
        $this->expectException(\DomainException::class);

        new InventorySnapshotSession(
            companyId: InventorySnapshotSessionBuilder::DEFAULT_COMPANY_ID,
            source: MarketplaceType::OZON,
            triggerType: SnapshotTriggerType::Manual,
            expectedPages: -1,
        );
    }

    public function testIncrementReceivedPagesThrowsWhenByIsNegative(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $this->expectException(\DomainException::class);

        $session->incrementReceivedPages(-1);
    }

    public function testSetReceivedPagesThrowsWhenNegative(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $this->expectException(\DomainException::class);

        $session->setReceivedPages(-1);
    }

    public function testSetExpectedPagesThrowsWhenNegative(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()->build();

        $this->expectException(\DomainException::class);

        $session->setExpectedPages(-5);
    }
}
