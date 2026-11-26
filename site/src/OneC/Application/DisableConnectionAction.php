<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\OneC\Infrastructure\Query\ConnectionQuery;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DisableConnectionAction
{
    public function __construct(private EntityManagerInterface $em, private ConnectionQuery $connections)
    {
    }

    public function __invoke(string $connectionId, string $ownerId, string $companyId): void
    {
        $this->em->wrapInTransaction(function () use ($connectionId, $ownerId, $companyId): void {
            $connection = $this->connections->lockOwned($ownerId, $connectionId);
            if ('disabled' === $connection->status) {
                return;
            }
            $status = $connection->status;
            $connection->disable();
            $this->em->persist(new AuditLog($companyId, OneCConnection::class, $connection->id, AuditLogAction::UPDATE, ['status' => [$status, 'disabled'], 'token' => [null, 'revoked']], $ownerId));
        });
    }
}
