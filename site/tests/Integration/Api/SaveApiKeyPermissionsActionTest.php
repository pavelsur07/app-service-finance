<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\Application\SaveApiKeyPermissionsAction;
use App\Api\Application\Service\ApiKeyAudit;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Entity\ApiKey;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Exception\ApiKeyOwnerRequiredException;
use App\Api\Exception\ApiKeyPermissionsConflictException;
use App\Api\Exception\InvalidApiKeyPermissionsException;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Facade\CompanyFacade;
use App\Tests\Builders\Api\ApiKeyBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;

final class SaveApiKeyPermissionsActionTest extends IntegrationTestCase
{
    private ApiKey $key;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->ownerId = (string) $owner->getId();
        $this->key = ApiKeyBuilder::aKey()->withCompanyId((string) $company->getId())->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($this->key);
        $this->em->flush();
    }

    public function testSavesExplicitPreparationAtomicallyWithVersionAndAllowlistedAudit(): void
    {
        $expiry = $this->key->getExpiresAt()->getTimestamp();
        $key = $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), ['projects.read', 'accounts.read', 'projects.read'], [], 1);
        self::assertSame(2, $key->getVersion());
        self::assertSame(['accounts.read', 'projects.read'], $key->getSelectedScopes());
        self::assertSame([], $key->getEnabledResources());
        self::assertSame($expiry, $key->getExpiresAt()->getTimestamp());
        $rawAudit = $this->connection->fetchOne('SELECT diff FROM audit_log WHERE entity_id = ?', [$key->getId()]);
        self::assertIsString($rawAudit);
        self::assertSame(['selectedScopes' => [[], ['accounts.read', 'projects.read']]], json_decode($rawAudit, true, 512, JSON_THROW_ON_ERROR));
        $this->em->clear();
        $saved = self::getContainer()->get(ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), $key->getCompanyId());
        self::assertNotNull($saved);
        self::assertSame(['accounts.read', 'projects.read'], $saved->getSelectedScopes());
    }

    public function testNoopDoesNotAuditOrIncrementVersion(): void
    {
        $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), [], [], 1);
        self::assertSame(1, $this->key->getVersion());
        self::assertSame(0, $this->auditCount());
    }

    public function testOldVersionCannotOverwriteSavedPermissions(): void
    {
        $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), ['accounts.read'], [], 1);
        try {
            $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), ['projects.read'], [], 1);
            self::fail('An old version must conflict.');
        } catch (ApiKeyPermissionsConflictException) {
            self::assertSame('["accounts.read"]', $this->connection->fetchOne('SELECT selected_scopes FROM api_keys WHERE id = ?', [$this->key->getId()]));
            self::assertSame(1, $this->auditCount());
        }
    }

    public function testRefreshDetectsConcurrentVersionEvenWithStaleIdentityMap(): void
    {
        $this->connection->executeStatement("UPDATE api_keys SET selected_scopes = '[\"projects.read\"]', version = version + 1 WHERE company_id = ? AND id = ?", [$this->key->getCompanyId(), $this->key->getId()]);
        self::assertSame(1, $this->key->getVersion());
        $this->expectException(ApiKeyPermissionsConflictException::class);
        $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), ['accounts.read'], [], 1);
    }

    /** @return iterable<array{list<string>, list<string>}> */
    public static function invalidPermissions(): iterable
    {
        yield [['*'], []];
        yield [['accounts.create'], []];
        yield [['accounts.read'], ['unknown']];
        yield [['accounts.read'], ['accounts']];
    }

    /** @param list<string> $scopes
     * @param list<string> $resources
     */
    #[DataProvider('invalidPermissions')]
    public function testUnknownScopesAndEnablingDisconnectedResourcesAreRejected(array $scopes, array $resources): void
    {
        $this->expectException(InvalidApiKeyPermissionsException::class);
        $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), $scopes, $resources, 1);
    }

    public function testOwnerOfADoesNotGainManagementAsMemberOfB(): void
    {
        $otherOwner = UserBuilder::aUser()->withIndex(2)->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $actor = self::getContainer()->get(CompanyFacade::class)->findById($this->key->getCompanyId())?->getUser();
        self::assertNotNull($actor);
        $member = CompanyMemberBuilder::aMember()->withCompany($other)->withUser($actor)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($other);
        $this->em->persist($member);
        $this->em->flush();
        $this->expectException(ApiKeyOwnerRequiredException::class);
        $this->save()((string) $other->getId(), $this->ownerId, $this->key->getId(), ['accounts.read'], [], 1);
    }

    public function testMissingScopedKeyIsRejected(): void
    {
        $this->expectException(ApiKeyNotFoundException::class);
        $this->save()($this->key->getCompanyId(), $this->ownerId, 'missing', [], [], 1);
    }

    public function testFailureAfterSqlUpdateRollsBackPermissionsAndAudit(): void
    {
        $listener = new class {
            public function postUpdate(PostUpdateEventArgs $event): void
            {
                if ($event->getObject() instanceof ApiKey) {
                    throw new \RuntimeException('Simulated post-update failure.');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);
        try {
            $this->save()($this->key->getCompanyId(), $this->ownerId, $this->key->getId(), ['accounts.read'], [], 1);
            self::fail('Transaction must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated post-update failure.', $exception->getMessage());
            self::assertSame('[]', $this->connection->fetchOne('SELECT selected_scopes FROM api_keys WHERE id = ?', [$this->key->getId()]));
            self::assertSame(0, $this->auditCount());
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postUpdate], $listener);
        }
    }

    private function auditCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(id) FROM audit_log WHERE entity_id = ?', [$this->key->getId()]);
    }

    private function save(): SaveApiKeyPermissionsAction
    {
        return new SaveApiKeyPermissionsAction(self::getContainer()->get(ApiKeyRepository::class), $this->em, new ApiOwnerGuard(self::getContainer()->get(CompanyFacade::class)), new ApiKeyAudit($this->em));
    }
}
