<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\ReconciliationSessionStatus;
use App\Marketplace\Repository\ReconciliationSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Сессия сверки данных Ozon с xlsx-отчётом «Детализация начислений».
 *
 * Хранит результат CostReconciliationQuery::reconcile() целиком в JSON (resultJson),
 * чтобы не плодить колонки при изменении структуры ответа Query.
 */
#[ORM\Entity(repositoryClass: ReconciliationSessionRepository::class)]
#[ORM\Table(name: 'marketplace_reconciliation_sessions')]
#[ORM\Index(columns: ['company_id', 'marketplace', 'created_at'], name: 'idx_recon_session_lookup')]
class ReconciliationSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $marketplace;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'string', length: 255)]
    private string $originalFilename;

    #[ORM\Column(type: 'string', length: 512)]
    private string $storedFilePath;

    #[ORM\Column(type: 'string', length: 16, enumType: ReconciliationSessionStatus::class)]
    private ReconciliationSessionStatus $status = ReconciliationSessionStatus::PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resultJson = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $originalFilename,
        string $storedFilePath,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($marketplace);
        Assert::notEmpty($originalFilename);
        Assert::notEmpty($storedFilePath);

        $this->id               = Uuid::uuid7()->toString();
        $this->companyId        = $companyId;
        $this->marketplace      = $marketplace;
        $this->periodFrom       = $periodFrom;
        $this->periodTo         = $periodTo;
        $this->originalFilename = $originalFilename;
        $this->storedFilePath   = $storedFilePath;
        $this->createdAt        = new \DateTimeImmutable();
    }

    // --- Бизнес-методы ---

    /**
     * @param array<string, mixed> $reconcileResult ровно то, что вернул CostReconciliationQuery::reconcile()
     */
    public function markCompleted(array $reconcileResult): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Only pending session can be completed.');
        }

        $this->resultJson  = json_encode($reconcileResult, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->status      = ReconciliationSessionStatus::COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $errorMessage): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Only pending session can be failed.');
        }

        $this->status       = ReconciliationSessionStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->completedAt  = new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDecodedResult(): ?array
    {
        if ($this->resultJson === null) {
            return null;
        }

        return json_decode($this->resultJson, true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Getters ---

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getMarketplace(): string { return $this->marketplace; }
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function getStoredFilePath(): string { return $this->storedFilePath; }
    public function getStatus(): ReconciliationSessionStatus { return $this->status; }
    public function getResultJson(): ?string { return $this->resultJson; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}
