<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Enum\AuditLogAction;
use App\Shared\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_company_created_at', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_audit_log_entity_created_at', columns: ['entity_class', 'entity_id', 'created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(name: 'entity_class', length: 255)]
    private string $entityClass;

    #[ORM\Column(name: 'entity_id', length: 255)]
    private string $entityId;

    #[ORM\Column(enumType: AuditLogAction::class)]
    private AuditLogAction $action;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $diff;

    #[ORM\Column(name: 'actor_user_id', type: 'guid', nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $diff
     */
    public function __construct(
        string $companyId,
        string $entityClass,
        string $entityId,
        AuditLogAction $action,
        ?array $diff = null,
        ?string $actorUserId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->companyId = $companyId;
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->action = $action;
        $this->diff = $diff;
        $this->actorUserId = $actorUserId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getAction(): AuditLogAction
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDiff(): ?array
    {
        return $this->diff;
    }

    public function getActorUserId(): ?string
    {
        return $this->actorUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
