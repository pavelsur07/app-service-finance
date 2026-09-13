<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Service\ApiKeyAudit;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Entity\ApiKey;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Repository\ApiKeyRepository;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class RevokeApiKeyAction
{
    public function __construct(private ApiKeyRepository $repository, private EntityManagerInterface $em, private ApiOwnerGuard $owner, private ApiKeyAudit $audit, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $userId, string $keyId): ApiKey
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $userId, $keyId): ApiKey {
            $this->owner->assertOwner($companyId, $userId);
            $key = $this->repository->findOneByIdAndCompany($keyId, $companyId, forUpdate: true) ?? throw new ApiKeyNotFoundException();
            if (null !== $key->getRevokedAt()) {
                return $key;
            }
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $key->revoke($now);
            $this->audit->record($key, $userId, AuditLogAction::UPDATE, ['revokedAt' => [null, $now->format(DATE_ATOM)]]);

            return $key;
        });
    }
}
