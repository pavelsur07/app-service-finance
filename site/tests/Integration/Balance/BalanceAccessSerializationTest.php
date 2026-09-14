<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

final class BalanceAccessSerializationTest extends IntegrationTestCase
{
    public function testCompanySharedLockSerializesRevocationAndFreshReadsSeeChanges(): void
    {
        $first = DriverManager::getConnection($this->connection->getParams());
        $second = DriverManager::getConnection($this->connection->getParams());
        $schema = 'balance_access_'.str_replace('-', '', Uuid::uuid7()->toString());
        $company = Uuid::uuid7()->toString();
        $owner = Uuid::uuid7()->toString();
        $member = Uuid::uuid7()->toString();
        $role = Uuid::uuid7()->toString();
        $first->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $first->executeStatement('SET search_path TO '.$schema);
            $second->executeStatement('SET search_path TO '.$schema);
            $first->executeStatement('CREATE TABLE companies (id uuid PRIMARY KEY,user_id uuid NOT NULL)');
            $first->executeStatement('CREATE TABLE company_role (id uuid PRIMARY KEY,company_id uuid,permissions json NOT NULL)');
            $first->executeStatement('CREATE TABLE company_members (company_id uuid,user_id uuid,role text,role_id uuid,status text)');
            $first->insert('companies', ['id' => $company, 'user_id' => $owner]);
            $first->insert('company_role', ['id' => $role, 'company_id' => $company, 'permissions' => '{"finance":"write"}']);
            $first->insert('company_members', ['company_id' => $company, 'user_id' => $member, 'role' => 'OPERATOR', 'role_id' => $role, 'status' => 'ACTIVE']);
            $repository = new CompanyRepository(self::getContainer()->get(ManagerRegistry::class), $first);
            $first->beginTransaction();
            self::assertTrue($repository->financialAccess($company, $member, true)['write']);
            $second->executeStatement("SET lock_timeout='100ms'");
            $second->beginTransaction();
            try {
                $second->fetchOne('SELECT id FROM companies WHERE id=? FOR UPDATE', [$company]);
                self::fail('Permission writer bypassed the financial mutation company lock.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
                $second->rollBack();
            }
            $first->commit();
            $second->beginTransaction();
            $second->fetchOne('SELECT id FROM companies WHERE id=? FOR UPDATE', [$company]);
            $second->update('company_role', ['permissions' => '{"finance":"read"}'], ['id' => $role]);
            $second->commit();
            $first->beginTransaction();
            self::assertFalse($repository->financialAccess($company, $member, true)['write']);
            self::assertTrue($repository->financialAccess($company, $member)['read']);
            $first->commit();
            $second->beginTransaction();
            $second->fetchOne('SELECT id FROM companies WHERE id=? FOR UPDATE', [$company]);
            $second->update('company_members', ['status' => 'DISABLED'], ['company_id' => $company, 'user_id' => $member]);
            $second->commit();
            self::assertFalse($repository->financialAccess($company, $member)['read']);
            self::assertTrue($repository->financialAccess($company, $owner)['owner']);
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $first->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $first->close();
            $second->close();
        }
    }
}
