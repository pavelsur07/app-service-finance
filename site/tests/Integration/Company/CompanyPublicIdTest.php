<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260913090000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class CompanyPublicIdTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->executeStatement('CREATE SCHEMA company_public_id_test');
        $this->connection->executeStatement('CREATE TABLE company_public_id_test.companies (LIKE public.companies INCLUDING ALL)');
        $this->connection->executeStatement('ALTER TABLE company_public_id_test.companies DROP COLUMN IF EXISTS public_id');
        $this->connection->executeStatement('SET LOCAL search_path TO company_public_id_test, public');
    }

    public function testBackfillsExistingCompaniesAndKeepsLegacyInsertsCompatible(): void
    {
        $owner = UserBuilder::aUser()->withIndex(903)->build();
        $this->em->persist($owner);
        $this->em->flush();
        $ids = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($ids as $id) {
            $this->insertLegacyCompany($id, (string) $owner->getId());
        }
        $this->migrate();

        self::assertSame($ids, $this->connection->fetchFirstColumn('SELECT id FROM companies ORDER BY id'));
        self::assertSame([100000, 100001], $this->connection->fetchFirstColumn('SELECT public_id FROM companies ORDER BY public_id'));
        $third = Uuid::uuid7()->toString();
        $this->insertLegacyCompany($third, (string) $owner->getId());
        self::assertSame(100002, $this->connection->fetchOne('SELECT public_id FROM companies WHERE id = ?', [$third]));
        $this->connection->executeStatement('DELETE FROM companies WHERE id = ?', [$third]);
        $this->insertLegacyCompany(Uuid::uuid7()->toString(), (string) $owner->getId());
        self::assertSame(100003, $this->connection->fetchOne('SELECT MAX(public_id) FROM companies'));
    }

    public function testOrmReceivesPublicIdAfterFlushAndPreservesItAcrossUpdates(): void
    {
        $this->migrate();
        $company = CompanyBuilder::aCompany()->withIndex(904)->build();
        $uuid = $company->getId();
        self::assertNull($company->getPublicId());
        $owner = $company->getUser();
        self::assertNotNull($owner);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        self::assertSame(100000, $company->getPublicId());
        $company->setName('Updated name');
        $this->em->flush();
        $this->em->clear();

        $facade = static::getContainer()->get(CompanyFacade::class);
        $loaded = $facade->findByPublicId(100000);
        self::assertInstanceOf(Company::class, $loaded);
        self::assertSame($uuid, $loaded->getId());
        self::assertSame('Updated name', $loaded->getName());
        self::assertSame(100000, $loaded->getPublicId());
        self::assertNull($facade->findByPublicId(100001));
        self::assertNull($facade->findByPublicId(-1));
    }

    public function testPublicIdUniquenessIsEnforcedByDatabase(): void
    {
        $this->migrate();
        $company = CompanyBuilder::aCompany()->withIndex(905)->build();
        $owner = $company->getUser();
        self::assertNotNull($owner);
        $this->em->persist($owner);
        $this->em->persist($company);
        $other = CompanyBuilder::aCompany()->withIndex(906)->withOwner($owner)->build();
        $this->em->persist($other);
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement('UPDATE companies SET public_id = ? WHERE id = ?', [$company->getPublicId(), $other->getId()]);
    }

    public function testPublicIdCannotBeNull(): void
    {
        $this->migrate();
        $company = CompanyBuilder::aCompany()->withIndex(907)->build();
        $owner = $company->getUser();
        self::assertNotNull($owner);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        $this->expectException(NotNullConstraintViolationException::class);
        $this->connection->executeStatement('UPDATE companies SET public_id = NULL WHERE id = ?', [$company->getId()]);
    }

    public function testConcurrentTransactionsReceiveDistinctIdsAndRollbackDoesNotReuseOne(): void
    {
        // Independent physical connections: DAMA's shared connection cannot exercise concurrent transactions.
        $params = $this->connection->getParams();
        $params['driverClass'] = Driver::class;
        unset($params['wrapperClass']);
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $schemaName = 'company_public_id_'.str_replace('-', '', Uuid::uuid7()->toString());
        $first->executeStatement('CREATE SCHEMA '.$schemaName);
        try {
            foreach ([$first, $second] as $connection) {
                $connection->executeStatement('SET search_path TO '.$schemaName);
            }
            $first->executeStatement('CREATE TABLE companies (id UUID PRIMARY KEY)');
            $path = dirname(__DIR__, 3).'/migrations/Version20260913090000.php';
            self::assertFileExists($path);
            require_once $path;
            $migration = new Version20260913090000($first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $first->beginTransaction();
            $second->beginTransaction();
            self::assertSame(100000, $first->fetchOne('INSERT INTO companies (id) VALUES (?) RETURNING public_id', [Uuid::uuid7()->toString()]));
            // First insert is still uncommitted when the second transaction obtains its ID.
            self::assertSame(100001, $second->fetchOne('INSERT INTO companies (id) VALUES (?) RETURNING public_id', [Uuid::uuid7()->toString()]));
            $first->rollBack();
            $second->commit();
            self::assertSame(100002, $first->fetchOne('INSERT INTO companies (id) VALUES (?) RETURNING public_id', [Uuid::uuid7()->toString()]));
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
            $first->executeStatement('DROP SCHEMA '.$schemaName.' CASCADE');
            $first->close();
            $second->close();
        }
    }

    private function migrate(): void
    {
        $path = dirname(__DIR__, 3).'/migrations/Version20260913090000.php';
        self::assertFileExists($path);
        require_once $path;
        $migration = new Version20260913090000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertLegacyCompany(string $id, string $ownerId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO companies (id, user_id, name, minimum_balance_amount_minor, minimum_balance_currency) VALUES (?, ?, 'Legacy company', 0, 'RUB')",
            [$id, $ownerId],
        );
    }
}
