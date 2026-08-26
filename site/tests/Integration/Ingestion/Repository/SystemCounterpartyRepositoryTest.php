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
        $this->ensureOzonSystemCounterparty();

        /** @var SystemCounterpartyRepository $repository */
        $repository = self::getContainer()->get(SystemCounterpartyRepository::class);

        $counterparty = $repository->findBySource(IngestSource::OZON);

        self::assertNotNull($counterparty);
        self::assertSame(IngestSource::OZON, $counterparty->getSource());
        self::assertSame('Ozon', $counterparty->getName());
    }

    /**
     * Тест обязан сам обеспечить своё предусловие, а не полагаться на данные,
     * засеянные миграцией Version20260619110000.
     *
     * DbReset восстанавливает справочные данные после TRUNCATE, поэтому строка
     * переживает прогон PostgresResetTestCase. Но большинство тестов DbReset не
     * вызывают вовсе, и на БД, испорченной до появления восстановления, чинить
     * её будет некому — предусловие остаётся за тестом.
     *
     * ON CONFLICT DO NOTHING делает вставку идемпотентной: строка есть — берём
     * её, нет — создаём. DAMA откатит вставку после теста. Тот же приём уже
     * применён в NormalizeRawRecordActionTest.
     */
    private function ensureOzonSystemCounterparty(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO system_counterparties (id, source, name, created_at)
                VALUES (:id, :source, :name, :createdAt)
                ON CONFLICT (source) DO NOTHING
                SQL,
            [
                'id' => '1cbbfc7c-72ad-5505-8743-be71bdde6dc1',
                'source' => IngestSource::OZON->value,
                'name' => 'Ozon',
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ],
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
