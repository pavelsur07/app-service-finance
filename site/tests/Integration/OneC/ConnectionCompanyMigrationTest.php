<?php

declare(strict_types=1);

namespace App\Tests\Integration\OneC;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Ramsey\Uuid\Uuid;

final class ConnectionCompanyMigrationTest extends IntegrationTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261127090000';

    private string $userId;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = Uuid::uuid7()->toString();
        $this->companyId = Uuid::uuid7()->toString();
        $user = UserBuilder::aUser()->withId($this->userId)->withEmail($this->userId.'@example.test')->build();
        $this->em->persist($user);
        $this->em->persist(CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($user)->build());
        $this->em->flush();
        $this->migrate(Direction::DOWN);
    }

    public function testCompanyComesFromCreateAuditRecord(): void
    {
        $id = $this->oldConnection();
        $this->audit($id, $this->companyId);

        $this->migrate(Direction::UP);

        self::assertSame(
            ['company_id' => $this->companyId, 'created_by_user_id' => $this->userId],
            $this->connection->fetchAssociative('SELECT company_id, created_by_user_id FROM onec_connection WHERE id = ?', [$id]),
        );
    }

    public function testConnectionWithoutAuditRecordStopsTheMigration(): void
    {
        $this->audit($this->oldConnection(), $this->companyId);
        $orphan = $this->oldConnection();

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage($orphan);
        $this->migrate(Direction::UP);
    }

    private function oldConnection(): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->insert('onec_connection', ['id' => $id, 'owner_id' => $this->userId, 'name' => 'База', 'token_hash' => hash('sha256', $id), 'status' => 'pending', 'versions' => '{}']);

        return $id;
    }

    private function audit(string $connectionId, string $companyId): void
    {
        $this->connection->insert('audit_log', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'entity_class' => 'App\OneC\Entity\OneCConnection',
            'entity_id' => $connectionId, 'action' => 'CREATE', 'actor_user_id' => $this->userId, 'created_at' => '2026-11-26 12:00:00.000000',
        ]);
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
