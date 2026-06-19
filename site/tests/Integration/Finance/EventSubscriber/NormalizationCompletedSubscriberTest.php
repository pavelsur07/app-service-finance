<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\EventSubscriber;

use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use App\Ingestion\Domain\Event\AffectedPeriod;
use App\Ingestion\Domain\Event\NormalizationCompletedEvent;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class NormalizationCompletedSubscriberTest extends IntegrationTestCase
{
    public function testDispatchesMarkDirtyMessagesForNewAndOldMoscowPeriods(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');
        $transport->reset();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $companyId = Uuid::uuid7()->toString();

        $dispatcher->dispatch(new NormalizationCompletedEvent(
            companyId: $companyId,
            rawRecordId: Uuid::uuid7()->toString(),
            affectedPeriods: [
                new AffectedPeriod(
                    shopRef: 'ozon:shop-1',
                    oldOccurredAt: new \DateTimeImmutable('2026-01-31 20:30:00 UTC'),
                    newOccurredAt: new \DateTimeImmutable('2026-02-01 00:30:00 UTC'),
                ),
            ],
        ));

        $messages = array_map(
            static fn ($envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        );

        self::assertCount(2, $messages);
        self::assertContainsOnlyInstancesOf(MarkPnlPeriodDirtyMessage::class, $messages);
        self::assertSame([2026, 2, 'ingest'], [$messages[0]->year, $messages[0]->month, $messages[0]->reasonValue]);
        self::assertSame([2026, 1, 'month_change'], [$messages[1]->year, $messages[1]->month, $messages[1]->reasonValue]);
    }
}
