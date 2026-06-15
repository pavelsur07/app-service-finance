<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Entity\IngestionTenantProbe;
use App\Tests\Integration\Ingestion\Fixtures\TenantVisibilityMessage;
use App\Tests\Integration\Ingestion\Fixtures\TenantVisibilityRecorder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

final class IngestionMessengerEndToEndCompanyFilterTest extends IntegrationTestCase
{
    public function testCompanyAwareMessageThroughTransportLimitsHandlerQueriesToMessageCompany(): void
    {
        $this->resetDb();

        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $probeA = new IngestionTenantProbe($companyA);
        $probeB = new IngestionTenantProbe($companyB);

        $this->em->persist($probeA);
        $this->em->persist($probeB);
        $this->em->flush();
        $this->em->clear();

        /** @var TenantVisibilityRecorder $recorder */
        $recorder = self::getContainer()->get(TenantVisibilityRecorder::class);
        $recorder->reset();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        $transport->reset();

        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new TenantVisibilityMessage($companyA));

        $envelopes = $transport->get();
        self::assertCount(1, $envelopes);

        $bus->dispatch($envelopes[0]->with(new ReceivedStamp('async_pipeline')));

        self::assertSame([$probeA->getId()], $recorder->visibleProbeIds());
        self::assertFalse($this->em->getFilters()->isEnabled('company'));
    }
}
