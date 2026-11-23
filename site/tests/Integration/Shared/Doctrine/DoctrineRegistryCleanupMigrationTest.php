<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;

final class DoctrineRegistryCleanupMigrationTest extends IntegrationTestCase
{
    private const CLEANUP_VERSION = 'DoctrineMigrations\Version20261119150000';
    private const OBSOLETE_VERSIONS = [
        'DoctrineMigrations\Version20260114130328',
        'DoctrineMigrations\Version20260201090000',
        'DoctrineMigrations\Version20260405000001',
        'DoctrineMigrations\Version20260405000002',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCleanupVersion();
    }

    public function testRemovesOnlyExactVersionsAndCanRunAgain(): void
    {
        $this->registerVersion('OtherMigrations\Version20260114130328');
        $this->registerVersion('DoctrineMigrations\Version20260405000002Example');
        $expected = $this->registry();
        foreach (self::OBSOLETE_VERSIONS as $version) {
            $this->registerVersion($version);
        }
        $schemaManager = $this->connection->createSchemaManager();
        $platform = $this->connection->getDatabasePlatform();
        $beforeSchema = $schemaManager->introspectSchema()->toSql($platform);

        $this->migrateUp();

        self::assertArrayHasKey(self::CLEANUP_VERSION, $this->registry());
        $this->forgetCleanupVersion();
        self::assertSame($expected, $this->registry());
        self::assertSame($beforeSchema, $schemaManager->introspectSchema()->toSql($platform));

        $this->migrateUp();

        self::assertArrayHasKey(self::CLEANUP_VERSION, $this->registry());
        $this->forgetCleanupVersion();
        self::assertSame($expected, $this->registry());
    }

    public function testMissingPrerequisiteIsIgnoredWithoutObsoleteVersions(): void
    {
        $this->forgetVersion('DoctrineMigrations\Version20260101090000');
        $expected = $this->registry();

        $this->migrateUp();

        self::assertArrayHasKey(self::CLEANUP_VERSION, $this->registry());
        $this->forgetCleanupVersion();
        self::assertSame($expected, $this->registry());
    }

    public function testDryRunPreservesRegistry(): void
    {
        foreach (self::OBSOLETE_VERSIONS as $version) {
            $this->registerVersion($version);
        }
        $before = $this->registry();

        $this->migrateUp(dryRun: true);

        self::assertSame($before, $this->registry());
    }

    #[DataProvider('violatedPreconditions')]
    public function testRollsBackWhenAPreconditionFails(?string $missingVersion, ?string $existingTable): void
    {
        $this->registerVersion(self::OBSOLETE_VERSIONS[0]);
        if (null !== $missingVersion) {
            $this->forgetVersion($missingVersion);
        }
        if (null !== $existingTable) {
            $this->connection->executeStatement(sprintf('CREATE TABLE %s (id INT PRIMARY KEY)', $existingTable));
        }
        $before = $this->registry();
        $transactionLevel = $this->connection->getTransactionNestingLevel();

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage($missingVersion ?? $existingTable ?? '');
        try {
            $this->migrateUp();
        } finally {
            self::assertSame($transactionLevel, $this->connection->getTransactionNestingLevel());
            self::assertSame($before, $this->registry());
        }
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function violatedPreconditions(): iterable
    {
        yield 'renamed version absent' => ['DoctrineMigrations\Version20260101090000', null];
        yield 'schema cleanup absent' => ['DoctrineMigrations\Version20261103150000', null];
        foreach (['bank_connection', 'bank_import_cursor', 'marketplace_raw_processing_step_runs', 'balance_categories', 'balance_category_links'] as $table) {
            yield $table => [null, $table];
        }
    }

    private function migrateUp(bool $dryRun = false): void
    {
        /** @var DependencyFactory $configuredFactory */
        $configuredFactory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuredFactory->getConfiguration()),
            new ExistingConnection($this->connection),
        );
        $plan = $factory->getMigrationPlanCalculator()->getPlanForVersions([new Version(self::CLEANUP_VERSION)], Direction::UP);
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setDryRun($dryRun));
    }

    private function registerVersion(string $version): void
    {
        $this->connection->insert('doctrine_migration_versions', [
            'version' => $version,
            'executed_at' => '2026-10-23 12:34:56',
            'execution_time' => 42,
        ]);
    }

    private function forgetCleanupVersion(): void
    {
        $this->forgetVersion(self::CLEANUP_VERSION);
    }

    private function forgetVersion(string $version): void
    {
        $this->connection->delete('doctrine_migration_versions', ['version' => $version]);
    }

    /** @return array<array<string, mixed>> */
    private function registry(): array
    {
        return $this->connection->fetchAllAssociativeIndexed(
            'SELECT version, executed_at, execution_time FROM doctrine_migration_versions ORDER BY version',
        );
    }
}
