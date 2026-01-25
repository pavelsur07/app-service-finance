<?php

namespace App\Telegram\Entity;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`import_jobs`')]
#[ORM\Index(name: 'idx_import_jobs_status', columns: ['status'])]
#[ORM\Index(name: 'idx_import_jobs_filehash', columns: ['file_hash'])]
class ImportJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    // Уникальный идентификатор задачи импорта (UUID хранится строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Компания, в которую загружается файл
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    // Пользователь Telegram, загрузивший файл (опционально)
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy = null;

    // Источник загрузки (например, 'telegram')
    #[ORM\Column(type: 'string', length: 32)]
    private string $source;

    // Оригинальное имя файла
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    // Хэш содержимого файла (sha256)
    #[ORM\Column(name: 'file_hash', type: 'string', length: 64)]
    private string $fileHash;

    // Текущий статус задачи
    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_QUEUED;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Время начала обработки
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    // Время завершения обработки
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    // Сообщение об ошибке (если задача завершилась неуспешно)
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(string $id, Company $company, string $source, string $filename, string $fileHash, ?TelegramUser $uploadedBy = null)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->source = $source;
        $this->filename = $filename;
        $this->fileHash = $fileHash;
        $this->uploadedBy = $uploadedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_QUEUED;
    }

    // Переводит задачу в состояние обработки
    public function start(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->startedAt = new \DateTimeImmutable();
    }

    // Помечает задачу завершенной успешно
    public function finishOk(): void
    {
        $this->status = self::STATUS_DONE;
        $this->finishedAt = new \DateTimeImmutable();
    }

    // Помечает задачу завершенной с ошибкой
    public function fail(string $message): void
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $message;
        $this->finishedAt = new \DateTimeImmutable();
    }

    // Возвращает UUID задачи
    public function getId(): ?string
    {
        return $this->id;
    }

    // Возвращает компанию
    public function getCompany(): Company
    {
        return $this->company;
    }

    // Устанавливает компанию
    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    // Возвращает пользователя, загрузившего файл
    public function getUploadedBy(): ?TelegramUser
    {
        return $this->uploadedBy;
    }

    // Устанавливает пользователя, загрузившего файл
    public function setUploadedBy(?TelegramUser $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    // Возвращает источник загрузки
    public function getSource(): string
    {
        return $this->source;
    }

    // Устанавливает источник загрузки
    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    // Возвращает имя файла
    public function getFilename(): string
    {
        return $this->filename;
    }

    // Устанавливает имя файла
    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    // Возвращает хэш файла
    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    // Устанавливает хэш файла
    public function setFileHash(string $fileHash): self
    {
        $this->fileHash = $fileHash;

        return $this;
    }

    // Возвращает статус задачи
    public function getStatus(): string
    {
        return $this->status;
    }

    // Устанавливает статус задачи
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    // Возвращает дату создания
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Возвращает дату начала обработки
    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    // Устанавливает дату начала обработки
    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    // Возвращает дату завершения обработки
    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    // Устанавливает дату завершения обработки
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    // Возвращает сообщение об ошибке
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    // Устанавливает сообщение об ошибке
    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
