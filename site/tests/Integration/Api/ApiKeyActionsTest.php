<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\Application\CreateApiKeyAction;
use App\Api\Application\RenameApiKeyAction;
use App\Api\Application\RevokeApiKeyAction;
use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Exception\ApiKeyOwnerRequiredException;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

final class ApiKeyActionsTest extends IntegrationTestCase
{
    private Company $company;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->ownerId = (string) $owner->getId();
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testCreateIndependentKeysAndPersistOnlyHashWithAllowlistedAudit(): void
    {
        $create = $this->createAction();
        $first = $create($this->companyId(), $this->ownerId, ' First ');
        $second = $create($this->companyId(), $this->ownerId, 'Second');
        $parsed = ApiKeySecret::parse($first->revealToken());
        self::assertNotNull($parsed);
        self::assertTrue($first->key->matchesSecret($parsed['secret']));
        self::assertNotSame($first->key->getId(), $second->key->getId());
        self::assertNotSame($first->revealToken(), $second->revealToken());
        $rows = $this->connection->fetchAllAssociative('SELECT name, secret_hash FROM api_keys WHERE company_id = ?', [$this->companyId()]);
        self::assertCount(2, $rows);
        self::assertSame('First', $rows[0]['name']);
        self::assertSame(ApiKeySecret::hash($parsed['secret']), $rows[0]['secret_hash']);
        $audit = $this->connection->fetchOne('SELECT diff FROM audit_log WHERE entity_id = ?', [$first->key->getId()]);
        self::assertIsString($audit);
        self::assertSame(['name', 'expiresAt'], array_keys(json_decode($audit, true, 512, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString($parsed['secret'], $audit);
        self::assertStringNotContainsString(ApiKeySecret::hash($parsed['secret']), $audit);
        self::assertStringNotContainsString($parsed['secret'], (string) json_encode($first));
        $this->expectException(\LogicException::class);
        serialize($first);
    }

    public function testRenameAndRevokePersistAndNoopsDoNotAuditOrExtendExpiry(): void
    {
        $key = $this->createKey();
        $expiry = $key->getExpiresAt();
        $rename = $this->renameAction();
        $revoke = $this->revokeAction();
        $rename($this->companyId(), $this->ownerId, $key->getId(), 'Renamed');
        $rename($this->companyId(), $this->ownerId, $key->getId(), ' Renamed ');
        $revoke($this->companyId(), $this->ownerId, $key->getId());
        $revokedAt = $key->getRevokedAt();
        $revoke($this->companyId(), $this->ownerId, $key->getId());
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(id) FROM audit_log WHERE entity_id = ?', [$key->getId()]));
        $this->em->clear();
        $saved = self::getContainer()->get(ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), $this->companyId());
        self::assertNotNull($saved);
        self::assertSame('Renamed', $saved->getName());
        self::assertSame($expiry->getTimestamp(), $saved->getExpiresAt()->getTimestamp());
        self::assertSame($revokedAt?->getTimestamp(), $saved->getRevokedAt()?->getTimestamp());
    }

    /** @return iterable<string, array{class-string}> */
    public static function writeActions(): iterable
    {
        yield 'create' => [CreateApiKeyAction::class];
        yield 'rename' => [RenameApiKeyAction::class];
        yield 'revoke' => [RevokeApiKeyAction::class];
    }

    /** @param class-string<CreateApiKeyAction|RenameApiKeyAction|RevokeApiKeyAction> $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('writeActions')]
    public function testOwnerOfCompanyAWhoIsMemberOfBDeniedByEveryWrite(string $class): void
    {
        $otherOwner = UserBuilder::aUser()->withIndex(2)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $member = CompanyMemberBuilder::aMember()->withCompany($otherCompany)->withUser($owner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);
        $this->em->persist($member);
        $this->em->flush();
        $this->expectException(ApiKeyOwnerRequiredException::class);
        $this->action($class)((string) $otherCompany->getId(), $this->ownerId, 'missing', 'name');
    }

    public function testRevokeRefreshesAlreadyRevokedKeyFromStaleIdentityMapWithoutNewAudit(): void
    {
        $key = $this->createKey();
        $revokedAt = new \DateTimeImmutable('2026-09-13T11:00:00Z');
        // Simulate another transaction winning the race after this manager loaded the key.
        $this->connection->executeStatement('UPDATE api_keys SET revoked_at = ?, version = version + 1 WHERE company_id = ? AND id = ?', [$revokedAt->format(DATE_ATOM), $this->companyId(), $key->getId()]);
        self::assertNull($key->getRevokedAt());
        $result = $this->revokeAction()($this->companyId(), $this->ownerId, $key->getId());
        self::assertSame($revokedAt->getTimestamp(), $result->getRevokedAt()?->getTimestamp());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(id) FROM audit_log WHERE entity_id = ?', [$key->getId()]));
    }

    public function testScopedRepositoryNeverReturnsOtherCompanyKey(): void
    {
        $key = $this->createKey();
        $repo = self::getContainer()->get(ApiKeyRepository::class);
        self::assertNull($repo->findOneByIdAndCompany($key->getId(), '99999999-9999-4999-8999-999999999999'));
        self::assertSame([], $repo->createListQueryBuilder('99999999-9999-4999-8999-999999999999')->getQuery()->getResult());
        self::assertCount(1, $repo->createListQueryBuilder($this->companyId())->getQuery()->getResult());
    }

    public function testRenameRejectsMissingScopedKey(): void
    {
        $this->expectException(ApiKeyNotFoundException::class);
        $this->renameAction()($this->companyId(), $this->ownerId, 'missing', 'new name');
    }

    public function testRevokeRejectsMissingScopedKey(): void
    {
        $this->expectException(ApiKeyNotFoundException::class);
        $this->revokeAction()($this->companyId(), $this->ownerId, 'missing');
    }

    public function testAuditFailureRollsBackKeyInsert(): void
    {
        $listener = new class {
            /** @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $event */
            public function postPersist(LifecycleEventArgs $event): void
            {
                if ($event->getObject() instanceof AuditLog) {
                    throw new \RuntimeException('Simulated audit persistence failure.');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $listener);
        try {
            $this->createAction()($this->companyId(), $this->ownerId, 'Must rollback');
            self::fail('Audit must fail the transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated audit persistence failure.', $exception->getMessage());
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(id) FROM api_keys WHERE company_id = ?', [$this->companyId()]));
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postPersist], $listener);
        }
    }

    /** @param class-string<CreateApiKeyAction|RenameApiKeyAction|RevokeApiKeyAction> $class */
    private function action(string $class): CreateApiKeyAction|RenameApiKeyAction|RevokeApiKeyAction
    {
        return match ($class) {
            CreateApiKeyAction::class => $this->createAction(),
            RenameApiKeyAction::class => $this->renameAction(),
            RevokeApiKeyAction::class => $this->revokeAction(),
        };
    }

    private function ownerGuard(): \App\Api\Application\Service\ApiOwnerGuard
    {
        return new \App\Api\Application\Service\ApiOwnerGuard(self::getContainer()->get(\App\Company\Facade\CompanyFacade::class));
    }

    private function createAction(): CreateApiKeyAction
    {
        return new CreateApiKeyAction($this->em, $this->ownerGuard(), new \App\Api\Application\Service\ApiKeyAudit($this->em), new \Symfony\Component\Clock\NativeClock('UTC'));
    }

    private function renameAction(): RenameApiKeyAction
    {
        return new RenameApiKeyAction(self::getContainer()->get(ApiKeyRepository::class), $this->em, $this->ownerGuard(), new \App\Api\Application\Service\ApiKeyAudit($this->em));
    }

    private function revokeAction(): RevokeApiKeyAction
    {
        return new RevokeApiKeyAction(self::getContainer()->get(ApiKeyRepository::class), $this->em, $this->ownerGuard(), new \App\Api\Application\Service\ApiKeyAudit($this->em), new \Symfony\Component\Clock\NativeClock('UTC'));
    }

    private function createKey(): ApiKey
    {
        return $this->createAction()($this->companyId(), $this->ownerId, 'Test key')->key;
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }
}
