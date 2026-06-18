<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\SystemCounterparty;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\SystemCounterpartyNotFoundException;
use App\Ingestion\Repository\SystemCounterpartyRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SystemCounterpartyRepositoryTest extends IntegrationTestCase
{
    public function testFindsGlobalCounterpartyBySource(): void
    {
        $counterparty = new SystemCounterparty(
            id: '1cbbfc7c-72ad-5505-8743-be71bdde6dc1',
            source: IngestSource::OZON,
            name: 'Ozon',
        );

        $this->em->persist($counterparty);
        $this->em->flush();
        $this->em->clear();

        /** @var SystemCounterpartyRepository $repository */
        $repository = self::getContainer()->get(SystemCounterpartyRepository::class);

        self::assertSame(
            $counterparty->getId(),
            $repository->findBySource(IngestSource::OZON)?->getId(),
        );
    }

    public function testGetBySourceThrowsWhenMissing(): void
    {
        /** @var SystemCounterpartyRepository $repository */
        $repository = self::getContainer()->get(SystemCounterpartyRepository::class);

        $this->expectException(SystemCounterpartyNotFoundException::class);

        $repository->getBySource(IngestSource::OZON_PERFORMANCE);
    }
}
