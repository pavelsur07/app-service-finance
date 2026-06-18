<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Command;

use App\Finance\Message\RebuildPnlPeriodMessage;
use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RebuildDirtyPnlPeriodsCommandTest extends IntegrationTestCase
{
    public function testCommandDispatchesPendingPeriodsOnly(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $pending = new PLDirtyPeriod($companyId, 2026, 2, '', PLDirtyPeriodReason::INGEST);
        $done = new PLDirtyPeriod($companyId, 2026, 3, '', PLDirtyPeriodReason::INGEST);
        $done->markRebuilding();
        $done->markDone();

        $this->em->persist($pending);
        $this->em->persist($done);
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.pnl_rebuild');
        $transport->reset();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:finance:rebuild-dirty-pnl-periods'));

        self::assertSame(0, $tester->execute(['--max' => 10]));

        $messages = array_map(
            static fn ($envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        );

        self::assertCount(1, $messages);
        self::assertInstanceOf(RebuildPnlPeriodMessage::class, $messages[0]);
        self::assertSame(2026, $messages[0]->year);
        self::assertSame(2, $messages[0]->month);
    }
}
