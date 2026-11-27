<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateConnectionAction
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(string $requestId, string $companyId, string $actorUserId, string $name): string
    {
        $token = bin2hex(random_bytes(32));
        $connection = new OneCConnection($requestId, $companyId, $actorUserId, $name, hash('sha256', $token));
        $this->em->wrapInTransaction(function () use ($actorUserId, $connection): void {
            $this->em->persist($connection);
            $this->em->persist(new AuditLog($connection->companyId, OneCConnection::class, $connection->id, AuditLogAction::CREATE, ['name' => [null, $connection->name]], $actorUserId));
        });

        return $token;
    }
}
