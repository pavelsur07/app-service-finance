<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\SystemCounterpartyNotFoundException;
use App\Ingestion\Repository\SystemCounterpartyRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SystemCounterpartyRepositoryTest extends IntegrationTestCase
{
    public function testFindsGlobalCounterpartyBySource(): void
    {
        // Контрагента здесь НЕ создаём: `ozon` и `wildberries` засеяны миграцией
        // Version20260619110000, а на `source` стоит уникальный индекс. Попытка
        // вставить второй `ozon` падает с SQLSTATE[23505] на любой чисто
        // мигрированной БД.
        /** @var SystemCounterpartyRepository $repository */
        $repository = self::getContainer()->get(SystemCounterpartyRepository::class);

        $counterparty = $repository->findBySource(IngestSource::OZON);

        self::assertNotNull($counterparty);
        self::assertSame(IngestSource::OZON, $counterparty->getSource());
        self::assertSame('Ozon', $counterparty->getName());
    }

    public function testGetBySourceThrowsWhenMissing(): void
    {
        /** @var SystemCounterpartyRepository $repository */
        $repository = self::getContainer()->get(SystemCounterpartyRepository::class);

        $this->expectException(SystemCounterpartyNotFoundException::class);

        $repository->getBySource(IngestSource::OZON_PERFORMANCE);
    }
}
