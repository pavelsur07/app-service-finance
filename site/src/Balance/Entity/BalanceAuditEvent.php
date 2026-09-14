<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_audit_events')]
#[ORM\Index(name: 'idx_balance_audit_object', columns: ['company_id', 'object_type', 'object_id', 'created_at'])]
class BalanceAuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(length: 40)]
    private string $objectType;

    #[ORM\Column(type: Types::GUID)]
    private string $objectId;

    #[ORM\Column(length: 60)]
    private string $action;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $authorId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $changes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $changes */
    public function __construct(string $companyId, string $objectType, string $objectId, string $action, ?string $authorId, array $changes)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($objectId);
        Assert::nullOrUuid($authorId);
        Assert::notWhitespaceOnly($objectType);
        Assert::maxLength($objectType, 40);
        Assert::notWhitespaceOnly($action);
        Assert::maxLength($action, 60);
        $this->objectType = $objectType;
        $this->objectId = $objectId;
        $this->action = $action;
        $this->authorId = $authorId;
        $this->changes = $changes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getObjectType(): string
    {
        return $this->objectType;
    }

    public function getObjectId(): string
    {
        return $this->objectId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
