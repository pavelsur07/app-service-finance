<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceOperationKind;
use App\Balance\Enum\BalanceOperationStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_operations')]
#[ORM\UniqueConstraint(name: 'uniq_balance_operation_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_balance_operation_number', columns: ['company_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_balance_operation_request', columns: ['company_id', 'request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_balance_operation_sequence', columns: ['company_id', 'posting_sequence'])]
#[ORM\Index(name: 'idx_balance_operation_date', columns: ['company_id', 'operation_date', 'posting_sequence'])]
#[ORM\Index(name: 'idx_balance_operation_status', columns: ['company_id', 'status', 'operation_date'])]
#[ORM\Index(name: 'idx_balance_operation_original', columns: ['company_id', 'original_operation_id'])]
class BalanceOperation
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::BIGINT)]
    private string $number;

    #[ORM\Column(length: 20, enumType: BalanceOperationKind::class)]
    private BalanceOperationKind $kind;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(length: 20, enumType: BalanceOperationStatus::class)]
    private BalanceOperationStatus $status = BalanceOperationStatus::DRAFT;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::GUID)]
    private string $authorId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $postedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $postingSequence = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $originalOperationId = null;

    #[ORM\Column(length: 128)]
    private string $requestKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, string $number, BalanceOperationKind $kind, \DateTimeImmutable $operationDate, string $authorId, string $requestKey, string $requestHash, string $reason = '', ?string $originalOperationId = null)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($authorId);
        Assert::regex($number, '/^[1-9][0-9]{0,18}$/D');
        Assert::true(bccomp($number, '9223372036854775807', 0) <= 0);
        Assert::notWhitespaceOnly($requestKey);
        Assert::maxLength($requestKey, 128);
        Assert::regex($requestHash, '/^[a-f0-9]{64}$/D');
        Assert::nullOrUuid($originalOperationId);
        $this->number = $number;
        $this->kind = $kind;
        $this->operationDate = $operationDate;
        $this->authorId = $authorId;
        $this->requestKey = $requestKey;
        $this->requestHash = $requestHash;
        $this->reason = $reason;
        $this->originalOperationId = $originalOperationId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getKind(): BalanceOperationKind
    {
        return $this->kind;
    }

    public function getOperationDate(): \DateTimeImmutable
    {
        return $this->operationDate;
    }

    public function getStatus(): BalanceOperationStatus
    {
        return $this->status;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getPostedBy(): ?string
    {
        return $this->postedBy;
    }

    public function getPostedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function getPostingSequence(): ?string
    {
        return $this->postingSequence;
    }

    public function getOriginalOperationId(): ?string
    {
        return $this->originalOperationId;
    }

    public function getRequestKey(): string
    {
        return $this->requestKey;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
