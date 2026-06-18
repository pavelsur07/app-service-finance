<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\CounterpartyRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CounterpartyRepositoryTest extends IntegrationTestCase
{
    public function testGetOrCreateUsesCompanyIdAndUpdatesNameWithoutFlush(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        /** @var CounterpartyRepository $repository */
        $repository = self::getContainer()->get(CounterpartyRepository::class);

        $counterpartyA = $repository->getOrCreate($companyA, IngestSource::OZON, 'ozon', 'Ozon Old');
        $counterpartyB = $repository->getOrCreate($companyB, IngestSource::OZON, 'ozon', 'Ozon Company B');
        $this->em->flush();
        $this->em->clear();

        $sameA = $repository->getOrCreate($companyA, IngestSource::OZON, 'ozon', 'Ozon New');
        $this->em->flush();

        self::assertSame($counterpartyA->getId(), $sameA->getId());
        self::assertSame('Ozon New', $sameA->getName());
        self::assertSame($counterpartyB->getId(), $repository->findByNaturalKey($companyB, IngestSource::OZON, 'ozon')?->getId());
    }
}
