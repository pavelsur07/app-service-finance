<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Performance;

use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Infrastructure\Performance\MarketplaceFlushPerformanceListener;
use App\Shared\Infrastructure\Performance\PerformanceOutcome;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class MarketplaceFlushPerformanceListenerTest extends TestCase
{
    public function testFlushWithRawDocumentIsRawStorageWriteInPostgres(): void
    {
        $events = $this->flush(true, insertions: [$this->bare(MarketplaceRawDocument::class)]);

        self::assertSame('storage_write', $events[0]['stage']);
        self::assertSame('postgres', $events[0]['backend']);
        self::assertNull($events[0]['rows']);
    }

    public function testFlushOfAccountingRowsIsFinancialPostingWithRowCount(): void
    {
        // Классификация — только по типу сущности, наполнение не нужно.
        $events = $this->flush(true, insertions: [$this->bare(MarketplaceSale::class), $this->bare(Document::class)], updates: [new \stdClass()]);

        self::assertSame('financial_posting', $events[0]['stage']);
        self::assertSame(2, $events[0]['rows']);
    }

    public function testFlushOfUnrelatedEntitiesIsNotMeasured(): void
    {
        $events = $this->flush(true, updates: [new \stdClass()]);

        self::assertSame(['handler'], array_column($events, 'stage'));
    }

    public function testDisabledDiagnosticsDoesNotEvenInspectTheUnitOfWork(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getUnitOfWork');
        $listener = new MarketplaceFlushPerformanceListener($recorder);

        $recorder->beginScope('X');
        $listener->preFlush(new PreFlushEventArgs($em));
        $listener->onFlush(new OnFlushEventArgs($em));
        $listener->postFlush(new PostFlushEventArgs($em));

        self::assertSame([], $handler->getRecords());
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     *
     * @return list<array<string, mixed>>
     */
    private function flush(bool $enabled, array $insertions = [], array $updates = []): array
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), $enabled);
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $listener = new MarketplaceFlushPerformanceListener($recorder);

        $recorder->beginScope('ProcessRawDocumentStepMessage', 'wb');
        $listener->preFlush(new PreFlushEventArgs($em));
        $listener->onFlush(new OnFlushEventArgs($em));
        $listener->postFlush(new PostFlushEventArgs($em));
        $recorder->endScope(PerformanceOutcome::Ok);

        return array_values(array_map(static fn ($r): array => $r->context, $handler->getRecords()));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function bare(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
