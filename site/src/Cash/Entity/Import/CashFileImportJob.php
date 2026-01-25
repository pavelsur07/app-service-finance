<?php

namespace App\Cash\Entity\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`cash_file_import_jobs`')]
#[ORM\Index(name: 'idx_cash_file_import_jobs_status', columns: ['status'])]
#[ORM\Index(name: 'idx_cash_file_import_jobs_filehash', columns: ['file_hash'])]
#[ORM\Index(name: 'idx_cash_file_import_jobs_company', columns: ['company_id'])]
class CashFileImportJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MoneyAccount $moneyAccount;

    #[ORM\Column(type: 'string', length: 32)]
    private string $source;

    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    #[ORM\Column(name: 'file_hash', type: 'string', length: 64)]
    private string $fileHash;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'json')]
    private array $mapping;

    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\ManyToOne(targetEntity: ImportLog::class)]
    #[ORM\JoinColumn(name: 'import_log_id', nullable: true, onDelete: 'SET NULL')]
    private ?ImportLog $importLog = null;

    public function __construct(
        string $id,
        Company $company,
        MoneyAccount $moneyAccount,
        string $source,
        string $filename,
        string $fileHash,
        array $mapping,
        array $options = [],
    ) {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->moneyAccount = $moneyAccount;
        $this->source = $source;
        $this->filename = $filename;
        $this->fileHash = $fileHash;
        $this->mapping = $mapping;
        $this->options = $options;
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_QUEUED;
    }

    public function start(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function finishOk(): void
    {
        $this->status = self::STATUS_DONE;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $message): void
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $message;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getMoneyAccount(): MoneyAccount
    {
        return $this->moneyAccount;
    }

    public function setMoneyAccount(MoneyAccount $moneyAccount): self
    {
        $this->moneyAccount = $moneyAccount;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function setFileHash(string $fileHash): self
    {
        $this->fileHash = $fileHash;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getMapping(): array
    {
        return $this->mapping;
    }

    public function setMapping(array $mapping): self
    {
        $this->mapping = $mapping;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getImportLog(): ?ImportLog
    {
        return $this->importLog;
    }

    public function setImportLog(?ImportLog $importLog): self
    {
        $this->importLog = $importLog;

        return $this;
    }
}
