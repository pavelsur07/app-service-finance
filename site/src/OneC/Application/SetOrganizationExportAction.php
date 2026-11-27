<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Entity\OneCConnection;
use App\OneC\Entity\OneCOrganization;
use App\OneC\Exception\ConnectionNotFoundException;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Галочка «Загружать»: разрешает приём пакетов по организации. Под блокировкой
 * подключения — той же, что держит приём части, поэтому снятая галочка не
 * пропускает часть, проверенную до снятия.
 */
final readonly class SetOrganizationExportAction
{
    public function __construct(private EntityManagerInterface $em, private OneCConnectionRepository $connections)
    {
    }

    public function __invoke(string $connectionId, string $companyId, string $organizationGuid, bool $allowed, string $actorUserId): void
    {
        $this->em->wrapInTransaction(function () use ($connectionId, $companyId, $organizationGuid, $allowed, $actorUserId): void {
            $connection = $this->connections->lockForCompany($connectionId, $companyId);
            $organization = $this->connections->organization($connection, $organizationGuid);
            if (!$organization instanceof OneCOrganization) {
                throw new ConnectionNotFoundException('Организация не найдена.');
            }
            if ($organization->exportAllowed === $allowed) {
                return;
            }
            $organization->exportAllowed = $allowed;
            $this->em->persist(new AuditLog($connection->companyId, OneCConnection::class, $connection->id, AuditLogAction::UPDATE, ['organization' => $organization->guid, 'export_allowed' => [!$allowed, $allowed]], $actorUserId));
        });
    }
}
