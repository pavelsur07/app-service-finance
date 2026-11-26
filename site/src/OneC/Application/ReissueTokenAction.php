<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\OneC\Infrastructure\Query\ConnectionQuery;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReissueTokenAction
{
    public function __construct(private EntityManagerInterface $em, private ConnectionQuery $connections)
    {
    }

    /** @return string новый токен, показывается один раз */
    public function __invoke(string $connectionId, string $ownerId, string $companyId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->em->wrapInTransaction(function () use ($connectionId, $ownerId, $companyId, $token): void {
            $connection = $this->connections->lockOwned($ownerId, $connectionId);
            $status = $connection->status;
            $connection->reissueToken(hash('sha256', $token));
            $this->em->persist(new AuditLog($companyId, OneCConnection::class, $connection->id, AuditLogAction::UPDATE, ['token' => [null, 'reissued'], 'status' => [$status, $connection->status]], $ownerId));
        });

        return $token;
    }
}
