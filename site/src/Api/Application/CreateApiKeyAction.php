<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\DTO\CreatedApiKey;
use App\Api\Application\Service\ApiKeyAudit;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateApiKeyAction
{
    public function __construct(private EntityManagerInterface $em, private ApiOwnerGuard $owner, private ApiKeyAudit $audit, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $userId, string $name): CreatedApiKey
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $userId, $name): CreatedApiKey {
            $this->owner->assertOwner($companyId, $userId);
            $secret = ApiKeySecret::generate();
            $key = new ApiKey($companyId, $name, $secret['publicIdentifier'], ApiKeySecret::hash($secret['secret']), $userId, $this->clock->now());
            $this->em->persist($key);
            $this->audit->record($key, $userId, AuditLogAction::CREATE, ['name' => [null, $key->getName()], 'expiresAt' => [null, $key->getExpiresAt()->format(DATE_ATOM)]]);

            return new CreatedApiKey($key, ApiKeySecret::format($secret['publicIdentifier'], $secret['secret']));
        });
    }
}
