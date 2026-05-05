<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\OzonTransactionTotalsCheckStatus;
use App\Marketplace\Repository\OzonTransactionTotalsCheckRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: OzonTransactionTotalsCheckRepository::class)]
#[ORM\Table(name: 'marketplace_ozon_transaction_totals_checks')]
#[ORM\Index(columns: ['company_id', 'period_from', 'period_to'], name: 'idx_ozon_totals_check_company_period')]
#[ORM\Index(columns: ['company_id', 'raw_document_id'], name: 'idx_ozon_totals_check_raw_document')]
#[ORM\Index(columns: ['company_id', 'status'], name: 'idx_ozon_totals_check_status')]
class OzonTransactionTotalsCheck
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $rawDocumentId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'string', length: 16, enumType: OzonTransactionTotalsCheckStatus::class)]
    private OzonTransactionTotalsCheckStatus $status;

    #[ORM\Column(type: 'string', length: 64, options: ['default' => 'transaction_totals'])]
    private string $checkType;

    #[ORM\Column(type: 'json')]
    private array $detailTotals = [];

    #[ORM\Column(type: 'json')]
    private array $ozonTotals = [];

    #[ORM\Column(type: 'json')]
    private array $diffs = [];

    #[ORM\Column(type: 'string', length: 32)]
    private string $tolerance;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $rawDocumentId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $tolerance = '0.01',
    ) {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->rawDocumentId = $rawDocumentId;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->status = OzonTransactionTotalsCheckStatus::SKIPPED;
        $this->checkType = 'transaction_totals';
        $this->tolerance = $tolerance;
        $this->checkedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function markOk(array $detailTotals, array $ozonTotals, array $diffs): self
    {
        return $this->mark(
            OzonTransactionTotalsCheckStatus::OK,
            $detailTotals,
            $ozonTotals,
            $diffs,
        );
    }

    public function markWarning(array $detailTotals, array $ozonTotals, array $diffs, ?string $message = null): self
    {
        return $this->mark(
            OzonTransactionTotalsCheckStatus::WARNING,
            $detailTotals,
            $ozonTotals,
            $diffs,
            $message,
        );
    }

    public function markFailed(array $detailTotals, array $ozonTotals, array $diffs, ?string $message = null): self
    {
        return $this->mark(
            OzonTransactionTotalsCheckStatus::FAILED,
            $detailTotals,
            $ozonTotals,
            $diffs,
            $message,
        );
    }

    public function markSkipped(?string $message = null): self
    {
        return $this->mark(
            OzonTransactionTotalsCheckStatus::SKIPPED,
            [],
            [],
            [],
            $message,
        );
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getRawDocumentId(): string { return $this->rawDocumentId; }
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getStatus(): OzonTransactionTotalsCheckStatus { return $this->status; }
    public function getCheckType(): string { return $this->checkType; }
    public function getDetailTotals(): array { return $this->detailTotals; }
    public function getOzonTotals(): array { return $this->ozonTotals; }
    public function getDiffs(): array { return $this->diffs; }
    public function getTolerance(): string { return $this->tolerance; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function mark(
        OzonTransactionTotalsCheckStatus $status,
        array $detailTotals,
        array $ozonTotals,
        array $diffs,
        ?string $message = null,
    ): self {
        $now = new \DateTimeImmutable();

        $this->status = $status;
        $this->detailTotals = $detailTotals;
        $this->ozonTotals = $ozonTotals;
        $this->diffs = $diffs;
        $this->errorMessage = $message;
        $this->checkedAt = $now;
        $this->updatedAt = $now;

        return $this;
    }
}
