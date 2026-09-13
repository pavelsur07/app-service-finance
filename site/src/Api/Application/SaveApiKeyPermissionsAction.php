<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Service\ApiKeyAudit;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Domain\ApiScopeCatalog;
use App\Api\Entity\ApiKey;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Exception\ApiKeyPermissionsConflictException;
use App\Api\Exception\InvalidApiKeyPermissionsException;
use App\Api\Repository\ApiKeyRepository;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SaveApiKeyPermissionsAction
{
    public function __construct(
        private ApiKeyRepository $repository,
        private EntityManagerInterface $em,
        private ApiOwnerGuard $owner,
        private ApiKeyAudit $audit,
    ) {
    }

    /**
     * @param array<mixed> $selectedScopes
     * @param array<mixed> $enabledResources
     */
    public function __invoke(string $companyId, string $userId, string $keyId, array $selectedScopes, array $enabledResources, int $expectedVersion): ApiKey
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $userId, $keyId, $selectedScopes, $enabledResources, $expectedVersion): ApiKey {
            $this->owner->assertOwner($companyId, $userId);
            $key = $this->repository->findOneByIdAndCompany($keyId, $companyId, forUpdate: true) ?? throw new ApiKeyNotFoundException();
            // The lock serializes writes; refreshing before this check detects stale browser versions.
            if ($key->getVersion() !== $expectedVersion) {
                throw new ApiKeyPermissionsConflictException();
            }
            $scopes = ApiScopeCatalog::normalizeScopes($selectedScopes);
            $resources = ApiScopeCatalog::normalizeResources($enabledResources);
            if ([] !== array_diff($resources, ApiScopeCatalog::connectedResources())) {
                // Preparation cannot pre-authorize a future server-side connection.
                throw new InvalidApiKeyPermissionsException();
            }
            $diff = [];
            if ($key->getSelectedScopes() !== $scopes) {
                $diff['selectedScopes'] = [$key->getSelectedScopes(), $scopes];
            }
            if ($key->getEnabledResources() !== $resources) {
                $diff['enabledResources'] = [$key->getEnabledResources(), $resources];
            }
            if ([] === $diff) {
                return $key;
            }
            $key->setPermissions($scopes, $resources);
            $this->audit->record($key, $userId, AuditLogAction::UPDATE, $diff);

            return $key;
        });
    }
}
