<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DisableConnectionAction
{
    public function __construct(private EntityManagerInterface $em, private OneCConnectionRepository $connections)
    {
    }

    public function __invoke(string $connectionId, string $companyId, string $actorUserId): void
    {
        $this->em->wrapInTransaction(function () use ($connectionId, $companyId, $actorUserId): void {
            $connection = $this->connections->lockForCompany($connectionId, $companyId);
            if ('disabled' === $connection->status) {
                return;
            }
            $status = $connection->status;
            $connection->disable();
            $this->em->persist(new AuditLog($connection->companyId, OneCConnection::class, $connection->id, AuditLogAction::UPDATE, ['status' => [$status, 'disabled'], 'token' => [null, 'revoked']], $actorUserId));
        });
    }
}
