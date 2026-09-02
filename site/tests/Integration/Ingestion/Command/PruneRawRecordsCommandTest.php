<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneRawRecordsCommandTest extends IntegrationTestCase
{
    public function testDryRunReportsNothingToDelete(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertStringContainsString('Pruned 0 raw record(s)', $tester->getDisplay());
    }

    /**
     * У необратимой команды умолчания нет: и «удалил, а не просили», и «не
     * удалил, а ждали» — одинаково плохие сюрпризы.
     *
     * @param array<string, bool|string> $options
     */
    #[DataProvider('invalidInvocationProvider')]
    public function testInvalidInvocationIsRejected(array $options): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute($options));
    }

    /**
     * @return iterable<string, array{options: array<string, bool|string>}>
     */
    public static function invalidInvocationProvider(): iterable
    {
        yield 'no action chosen' => ['options' => []];
        yield 'both actions chosen' => ['options' => ['--dry-run' => true, '--execute' => true]];
        yield 'zero window' => ['options' => ['--dry-run' => true, '--older-than-days' => '0']];
        yield 'negative window' => ['options' => ['--dry-run' => true, '--older-than-days' => '-1']];
        yield 'non-numeric limit' => ['options' => ['--dry-run' => true, '--limit' => 'all']];
    }

    /**
     * Осиротевший объект ничего не ломает, но место занимает, и убрать его
     * может только человек по пути из лога. Успешный код возврата спрятал бы
     * это от крона.
     */
    public function testOrphanedObjectMakesTheCommandFail(): void
    {
        // Хранилище подменяется ДО первого обращения к нему: инициализированный
        // сервис заменить уже нельзя, поэтому сырьё засевается прямым SQL, а не
        // через RawStorageFacade.
        self::getContainer()->set(ObjectStorageInterface::class, new class implements ObjectStorageInterface {
            public function write(string $path, string $contents): StoredObject
            {
                throw new \LogicException('Тест не пишет объекты.');
            }

            public function read(string $path): string
            {
                throw new \LogicException('Тест не читает объекты.');
            }

            public function readStream(string $path)
            {
                // Тест объекты не читает, но заглушка обязана вернуть ресурс:
                // сигнатура интерфейса не выражается нативным типом, и
                // «всегда бросает» здесь несовместимо с контрактом.
                $stream = fopen('php://memory', 'r+');
                if (false === $stream) {
                    throw new \RuntimeException('Не удалось открыть поток в памяти.');
                }

                return $stream;
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function delete(string $path): void
            {
                throw new \RuntimeException('storage is unavailable');
            }
        });

        $this->connection->executeStatement(
            "INSERT INTO ingest_raw_records
                 (id, company_id, connection_ref, shop_ref, source, resource_type, external_id,
                  storage_path, hash, byte_size, fetched_at, last_seen_at, sync_job_id,
                  normalization_status, created_at, updated_at)
             VALUES
                 (gen_random_uuid(), :company, 'conn-1', 'shop-main', 'ozon', 'prune_fixture', 'page-1',
                  'company/ozon/shop/prune/2025/01/01/job/page-1/hash.ndjson.gz', 'hash', 128,
                  :old, :old, :job, 'done', now(), now())",
            [
                'company' => Uuid::uuid7()->toString(),
                'old' => (new \DateTimeImmutable('-400 days'))->format('Y-m-d H:i:s.u'),
                'job' => Uuid::uuid7()->toString(),
            ],
        );

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('left in storage', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:ingestion:raw:prune'));
    }
}
