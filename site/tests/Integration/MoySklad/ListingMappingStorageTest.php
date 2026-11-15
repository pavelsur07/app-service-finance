<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261115153000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class ListingMappingStorageTest extends IntegrationTestCase
{
    public function testPersistsProductVariantAndClearHistoryWithTenantScopedLookups(): void
    {
        [$connection, $product, $variant] = $this->catalog();
        $repo = self::getContainer()->get(MoySkladListingMappingRepository::class);
        $first = MoySkladListingMappingRevisionBuilder::aRevision()->build();
        $second = MoySkladListingMappingRevisionBuilder::aRevision()->withRevision(2)->withTarget('variant', $variant->getExternalId())->build();
        $clear = MoySkladListingMappingRevisionBuilder::aRevision()->withRevision(3)->clear()->build();
        foreach ([$first, $second, $clear] as $revision) {
            $repo->append($revision);
        }
        $this->em->flush();
        $product->applySnapshot(new \App\MoySklad\Domain\ProductSnapshot($product->getExternalId(), 'Переименован', '', 'NEW', 'NEW', 0, true, new \DateTimeImmutable()), new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $latest = $repo->latest($connection->getCompanyId(), $connection->getId(), $first->getListingId());
        self::assertNotNull($latest);
        self::assertSame(3, $latest->getRevision());
        self::assertSame('clear', $latest->getOperation()->value);
        $savedFirst = $repo->acceptedRequest($connection->getCompanyId(), $first->getRequestId());
        self::assertNotNull($savedFirst);
        self::assertSame('Товар', $savedFirst->getTargetSnapshot()['name'] ?? null);
        self::assertSame('product', $savedFirst->getTargetType());
        self::assertSame('variant', $repo->acceptedRequest($connection->getCompanyId(), $second->getRequestId())?->getTargetType());
        self::assertNull($repo->latest('99999999-9999-4999-8999-999999999999', $connection->getId(), $first->getListingId()));
        self::assertNull($repo->acceptedRequest('99999999-9999-4999-8999-999999999999', $first->getRequestId()));
    }

    public function testMigrationDownRefusesNonEmptyHistory(): void
    {
        $this->catalog();
        $this->connection->insert('moysklad_listing_mapping_revisions', $this->row());
        require_once \dirname(__DIR__, 3).'/migrations/Version20261115153000.php';
        $migration = new Version20261115153000($this->connection, new NullLogger());
        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }

    public function testExistingTargetOfAnotherConnectionCannotBeLinked(): void
    {
        $this->catalog();
        $other = MoySkladConnectionBuilder::aConnection()->withIndex(2)->build();
        $other->setName('Other connection');
        $product = MoySkladProductBuilder::aProduct()->forConnection($other)->withIndex(2)->build();
        $this->em->persist($other);
        $this->em->persist($product);
        $this->em->flush();
        $row = array_replace($this->row(), ['product_external_id' => $product->getExternalId()]);
        try {
            $this->connection->insert('moysklad_listing_mapping_revisions', $row);
            self::fail('Cross-connection target accepted.');
        } catch (DriverException $error) {
            self::assertSame('23503', $error->getSQLState());
        }
    }

    public function testMigrationDownRechecksHistoryWhenQueuedSqlActuallyExecutes(): void
    {
        $this->catalog();
        require_once \dirname(__DIR__, 3).'/migrations/Version20261115153000.php';
        $migration = new Version20261115153000($this->connection, new NullLogger());
        $migration->down(new Schema());
        $this->connection->insert('moysklad_listing_mapping_revisions', $this->row());
        $this->expectException(DriverException::class);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidRows')]
    public function testDatabaseEnforcesRevisionAndTenantConstraints(array $changes, string $sqlState): void
    {
        $this->catalog();
        $row = $this->row();
        $this->connection->insert('moysklad_listing_mapping_revisions', $row);
        $row['id'] = Uuid::uuid7()->toString();
        $row['request_id'] = Uuid::uuid7()->toString();
        $row['listing_id'] = Uuid::uuid7()->toString();
        $row = array_replace($row, $changes);

        try {
            $this->connection->insert('moysklad_listing_mapping_revisions', $row);
            self::fail('Database accepted an invalid mapping revision.');
        } catch (DriverException $error) {
            self::assertSame($sqlState, $error->getSQLState());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRows(): iterable
    {
        yield 'unknown company' => [['company_id' => '99999999-9999-4999-8999-999999999999'], '23503'];
        yield 'unknown connection' => [['connection_id' => '99999999-9999-4999-8999-999999999999'], '23503'];
        yield 'unknown product' => [['product_external_id' => '99999999-9999-4999-8999-999999999999'], '23503'];
        yield 'unknown variant' => [['product_external_id' => null, 'variant_external_id' => '99999999-9999-4999-8999-999999999999'], '23503'];
        yield 'both targets' => [['variant_external_id' => '66666666-6666-4666-8666-000000000001'], '23514'];
        yield 'set without target' => [['product_external_id' => null], '23514'];
        yield 'clear with target' => [['operation' => 'clear'], '23514'];
        yield 'clear snapshot' => [['operation' => 'clear', 'product_external_id' => null], '23514'];
        yield 'set without snapshot' => [['target_snapshot' => null], '23514'];
        yield 'listing array' => [['listing_snapshot' => '[]'], '23514'];
        yield 'target array' => [['target_snapshot' => '[]'], '23514'];
        yield 'target JSON null' => [['target_snapshot' => 'null'], '23514'];
        yield 'bad operation' => [['operation' => 'unknown'], '23514'];
        yield 'bad revision' => [['revision' => 0], '23514'];
        yield 'bad expected revision' => [['expected_revision' => 1], '23514'];
        yield 'duplicate revision' => [['listing_id' => '55555555-5555-4555-8555-000000000001'], '23505'];
        yield 'duplicate request' => [['request_id' => '77777777-7777-4777-8777-000000000001'], '23505'];
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'id' => Uuid::uuid7()->toString(), 'company_id' => MoySkladConnectionBuilder::DEFAULT_COMPANY_ID,
            'connection_id' => MoySkladConnectionBuilder::DEFAULT_ID, 'listing_id' => '55555555-5555-4555-8555-000000000001',
            'revision' => 1, 'expected_revision' => 0, 'operation' => 'set',
            'product_external_id' => '44444444-4444-4444-8444-000000000001', 'variant_external_id' => null,
            'request_id' => '77777777-7777-4777-8777-000000000001', 'actor_user_id' => '22222222-2222-4222-8222-000000000001',
            'created_at' => '2026-11-15 00:00:00.123', 'listing_snapshot' => '{"id":"55555555-5555-4555-8555-000000000001","marketplace":"ozon"}',
            'target_snapshot' => '{"name":"Товар"}',
        ];
    }

    /** @return array{\App\MoySklad\Entity\MoySkladConnection, \App\MoySklad\Entity\MoySkladProduct, \App\MoySklad\Entity\MoySkladVariant} */
    private function catalog(): array
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $product = MoySkladProductBuilder::aProduct()->forConnection($connection)->build();
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($product)->build();
        foreach ([$connection, $product, $variant] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$connection, $product, $variant];
    }
}
