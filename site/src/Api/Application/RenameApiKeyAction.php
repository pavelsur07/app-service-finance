<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Service\ApiKeyAudit;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Domain\ApiKeyName;
use App\Api\Entity\ApiKey;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Repository\ApiKeyRepository;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RenameApiKeyAction
{
    public function __construct(private ApiKeyRepository $repository, private EntityManagerInterface $em, private ApiOwnerGuard $owner, private ApiKeyAudit $audit)
    {
    }

    public function __invoke(string $companyId, string $userId, string $keyId, string $name): ApiKey
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $userId, $keyId, $name): ApiKey {
            $this->owner->assertOwner($companyId, $userId);
            $key = $this->repository->findOneByIdAndCompany($keyId, $companyId) ?? throw new ApiKeyNotFoundException();
            $name = ApiKeyName::normalize($name);
            if ($key->getName() === $name) {
                return $key;
            }
            $old = $key->getName();
            $key->rename($name);
            $this->audit->record($key, $userId, AuditLogAction::UPDATE, ['name' => [$old, $name]]);

            return $key;
        });
    }
}
