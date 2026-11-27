<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReissueTokenAction
{
    public function __construct(private EntityManagerInterface $em, private OneCConnectionRepository $connections)
    {
    }

    /** @return string новый токен, показывается один раз */
    public function __invoke(string $connectionId, string $companyId, string $actorUserId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->em->wrapInTransaction(function () use ($connectionId, $companyId, $actorUserId, $token): void {
            $connection = $this->connections->lockForCompany($connectionId, $companyId);
            $status = $connection->status;
            $connection->reissueToken(hash('sha256', $token));
            $this->em->persist(new AuditLog($connection->companyId, OneCConnection::class, $connection->id, AuditLogAction::UPDATE, ['token' => [null, 'reissued'], 'status' => [$status, $connection->status]], $actorUserId));
        });

        return $token;
    }
}
