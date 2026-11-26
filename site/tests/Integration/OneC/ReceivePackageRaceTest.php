<?php

declare(strict_types=1);

namespace App\Tests\Integration\OneC;

use App\OneC\Application\ReceivePackageAction;
use App\OneC\Entity\OneCConnection;
use App\OneC\Exception\OneCApiException;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Ramsey\Uuid\Uuid;

/**
 * Конкурент успел закоммитить ту же часть между нашими проверками и вставкой:
 * отвечаем как на повтор, EntityManager остаётся открытым. Без DAMA-отката:
 * строки конкурента закоммичены другой сессией, база чистится после теста.
 */
final class ReceivePackageRaceTest extends PostgresResetTestCase
{
    private Connection $writer;
    private string $connectionId;
    private string $token;
    private string $baseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = DriverManager::getConnection($this->connection->getParams());
        $em = new EntityManager($this->writer, $this->em->getConfiguration());
        $ownerId = Uuid::uuid7()->toString();
        $this->connectionId = Uuid::uuid7()->toString();
        $this->token = bin2hex(random_bytes(32));
        $this->baseId = Uuid::uuid4()->toString();
        $connection = new OneCConnection($this->connectionId, $ownerId, 'Race', hash('sha256', $this->token));
        $connection->handshake($this->baseId, ['config_name' => 'БухгалтерияПредприятия', 'config_version' => '3.0.1.1'], true, new \DateTimeImmutable());
        $em->persist(UserBuilder::aUser()->withId($ownerId)->withEmail($ownerId.'@example.test')->build());
        $em->persist($connection);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->writer->close();
        parent::tearDown();
    }

    public function testCommittedCompetitorWithSameBytesIsADuplicate(): void
    {
        $packageId = Uuid::uuid7()->toString();
        $body = $this->body($packageId);
        $this->competitor($packageId, hash('sha256', $body));

        $result = self::getContainer()->get(ReceivePackageAction::class)($this->token, $body);

        self::assertSame(['accepted' => true, 'package_id' => $packageId, 'part' => 1, 'status' => 'stored', 'duplicate' => true], $result);
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM onec_package WHERE package_id = ?', [$packageId]));
    }

    public function testCommittedCompetitorWithOtherBytesIsAConflict(): void
    {
        $packageId = Uuid::uuid7()->toString();
        $this->competitor($packageId, str_repeat('a', 64));

        try {
            self::getContainer()->get(ReceivePackageAction::class)($this->token, $this->body($packageId));
            self::fail('Ожидался конфликт пакета.');
        } catch (OneCApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('package_conflict', $e->error);
        }
        self::assertTrue($this->em->isOpen());
    }

    private function competitor(string $packageId, string $sha256): void
    {
        $this->writer->insert('onec_package', [
            'id' => Uuid::uuid7()->toString(), 'connection_id' => $this->connectionId, 'package_id' => $packageId,
            'part' => 1, 'parts_total' => 1, 'kind' => 'invoices', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'include_open_outside_period' => 'false', 'processor_version' => '1.0', 'versions' => '{}', 'warnings' => '[]',
            'document_count' => 0, 'storage_path' => 'onec/race', 'sha256' => $sha256, 'byte_size' => 1, 'status' => 'stored',
            'received_at' => '2026-11-26 10:00:00',
        ]);
    }

    private function body(string $packageId): string
    {
        return json_encode([
            'package_id' => $packageId, 'base_id' => $this->baseId, 'kind' => 'invoices', 'processor_version' => '1.0',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'include_open_outside_period' => false,
            'part' => 1, 'parts_total' => 1, 'warnings' => [], 'documents' => [],
        ], \JSON_THROW_ON_ERROR);
    }
}
