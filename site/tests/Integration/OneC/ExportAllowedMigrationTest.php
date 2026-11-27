<?php

declare(strict_types=1);

namespace App\Tests\Integration\OneC;

use App\OneC\Entity\OneCConnection;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Ramsey\Uuid\Uuid;

final class ExportAllowedMigrationTest extends IntegrationTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261127120000';

    public function testExistingOrganizationsAreNotAllowedAndOldPartsHaveNoOrganizations(): void
    {
        $userId = Uuid::uuid7()->toString();
        $companyId = Uuid::uuid7()->toString();
        $connectionId = Uuid::uuid7()->toString();
        $user = UserBuilder::aUser()->withId($userId)->withEmail($userId.'@example.test')->build();
        $this->em->persist($user);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($user)->build());
        $this->em->persist(new OneCConnection($connectionId, $companyId, $userId, 'База', hash('sha256', $connectionId)));
        $this->em->flush();
        $this->migrate(Direction::DOWN);
        $db = $this->connection;
        $db->insert('onec_organization', ['connection_id' => $connectionId, 'guid' => Uuid::uuid4()->toString(), 'name' => 'ООО Ромашка', 'present' => true, 'enabled' => true], ['present' => 'boolean', 'enabled' => 'boolean']);
        $db->executeStatement("INSERT INTO onec_package (id, connection_id, package_id, part, parts_total, kind, period_from, period_to, include_open_outside_period,
            processor_version, versions, warnings, document_count, storage_path, sha256, byte_size, status, received_at)
            VALUES (?, ?, ?, 1, 1, 'invoices', '2026-11-01', '2026-11-30', FALSE, '1.0', '{}', '[]', 0, 'onec/old', 'x', 1, 'stored', now())",
            [Uuid::uuid7()->toString(), $connectionId, Uuid::uuid4()->toString()]);

        $this->migrate(Direction::UP);

        self::assertFalse($db->fetchOne('SELECT export_allowed FROM onec_organization WHERE connection_id = ?', [$connectionId]));
        self::assertNull($db->fetchOne('SELECT organization_guids FROM onec_package WHERE connection_id = ?', [$connectionId]));
        self::assertNull($db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'onec_organization' AND column_name = 'export_allowed'"));
    }

    private function migrate(string $direction): void
    {
        /** @var DependencyFactory $configured */
        $configured = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configured->getConfiguration()), new ExistingConnection($this->connection));
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions([new Version(self::VERSION)], $direction),
            new MigratorConfiguration(),
        );
    }
}
