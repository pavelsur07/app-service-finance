<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Repository\IngestCursorRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IngestCursorRepositoryTest extends IntegrationTestCase
{
    public function testGetOrCreatePersistsWithoutFlushAndFindOneUsesCompanyId(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        /** @var IngestCursorRepository $repository */
        $repository = self::getContainer()->get(IngestCursorRepository::class);

        $cursorA = $repository->getOrCreate($companyA, 'connection-1', 'resource-1', 'shop-1');
        $cursorB = $repository->getOrCreate($companyB, 'connection-1', 'resource-1', 'shop-1');
        $this->em->flush();
        $this->em->clear();

        self::assertSame($cursorA->getId(), $repository->findOne($companyA, 'connection-1', 'resource-1', 'shop-1')?->getId());
        self::assertSame($cursorB->getId(), $repository->findOne($companyB, 'connection-1', 'resource-1', 'shop-1')?->getId());
        self::assertNull($repository->findOne($companyA, 'connection-2', 'resource-1', 'shop-1'));
    }
}
