<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'product_imports',
    indexes: [
        new ORM\Index(name: 'idx_product_imports_company', columns: ['company_id']),
        new ORM\Index(name: 'idx_product_imports_status', columns: ['status']),
    ],
)]
class ProductImport
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    /**
     * Компания передаётся только как строковый UUID.
     * Прямая связь с Company entity запрещена правилами разработки.
     */
    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 500)]
    private string $filePath;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rowsTotal = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rowsCreated = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rowsSkipped = null;

    /** @var array<int, array{row: int, reason: string, message: string}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        string $id,
        string $companyId,
        string $filePath,
        string $originalName,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($filePath);
        Assert::notEmpty($originalName);

        $this->id           = $id;
        $this->companyId    = $companyId;
        $this->filePath     = $filePath;
        $this->originalName = $originalName;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRowsTotal(): ?int
    {
        return $this->rowsTotal;
    }

    public function getRowsCreated(): ?int
    {
        return $this->rowsCreated;
    }

    public function getRowsSkipped(): ?int
    {
        return $this->rowsSkipped;
    }

    public function getResultJson(): ?array
    {
        return $this->resultJson;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;

        return $this;
    }

    public function markDone(int $rowsTotal, int $rowsCreated, int $rowsSkipped, array $errors): self
    {
        $this->status      = self::STATUS_DONE;
        $this->rowsTotal   = $rowsTotal;
        $this->rowsCreated = $rowsCreated;
        $this->rowsSkipped = $rowsSkipped;
        $this->resultJson  = $errors;
        $this->finishedAt  = new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $errorMessage): self
    {
        $this->status     = self::STATUS_FAILED;
        $this->resultJson = [['error' => $errorMessage]];
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }
}
