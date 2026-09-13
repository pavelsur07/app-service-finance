<?php

declare(strict_types=1);

namespace App\Api\Application\Service;

use App\Api\Entity\ApiKey;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApiKeyAudit
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $diff */
    public function record(ApiKey $key, string $actorId, AuditLogAction $action, array $diff): void
    {
        $allowed = array_intersect_key($diff, array_flip(['name', 'expiresAt', 'revokedAt']));
        if ([] === $allowed) {
            return;
        }
        $this->em->persist(new AuditLog($key->getCompanyId(), ApiKey::class, $key->getId(), $action, $allowed, $actorId));
    }
}
