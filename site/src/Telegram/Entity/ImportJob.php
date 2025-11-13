<?php

namespace App\Telegram\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_jobs')]
#[ORM\Index(name: 'idx_import_jobs_status', columns: ['status'])]
#[ORM\Index(name: 'idx_import_jobs_filehash', columns: ['file_hash'])]
class ImportJob
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

    // Who uploaded (optional, when coming from Telegram)
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy = null;

    // e.g. 'telegram'
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

    public function __construct(Company $company, string $source, string $filename, string $fileHash, ?TelegramUser $uploadedBy = null)
    {
        $this->company = $company;
        $this->source = $source;
        $this->filename = $filename;
        $this->fileHash = $fileHash;
        $this->uploadedBy = $uploadedBy;
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

    // getters/setters ...
}
