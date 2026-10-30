<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use App\Cash\Exception\CashCategorySuggestionStateException;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * ИИ-подсказка статьи ДДС для операции без статьи (задача categorize_transactions).
 *
 * Подсказка ничего не меняет в операции сама: статью проставляет человек «Принять».
 * Не больше одной открытой подсказки на операцию — частичный unique в миграции
 * (`uniq_cash_category_suggestion_open`), повтор результата той же задачи —
 * unique (`task_id`, `transaction_id`).
 */
#[ORM\Entity(repositoryClass: CashCategorySuggestionRepository::class)]
#[ORM\Table(name: 'cash_category_suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_cash_category_suggestion_task_tx', columns: ['task_id', 'transaction_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_category_suggestion_open', columns: ['transaction_id'], options: ['where' => "status IN ('requested', 'suggested')"])]
#[ORM\Index(name: 'idx_cash_category_suggestion_company_tx', columns: ['company_id', 'transaction_id'])]
#[ORM\Index(name: 'idx_cash_category_suggestion_company_status', columns: ['company_id', 'status', 'requested_at'])]
class CashCategorySuggestion
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $transactionId;

    #[ORM\Column(type: Types::GUID)]
    private string $taskId;

    #[ORM\Column(length: 16, enumType: Status::class)]
    private Status $status = Status::REQUESTED;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $categoryId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $confidence = null;

    /** Вердикт сервиса для всей пачки: accepted / needs_review / rejected / failed. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $promptVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $decidedByUserId = null;

    public function __construct(
        string $companyId,
        string $transactionId,
        string $taskId,
        \DateTimeImmutable $requestedAt,
        ?string $id = null,
    ) {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::uuid($companyId);
        Assert::uuid($transactionId);
        Assert::uuid($taskId);

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->transactionId = $transactionId;
        $this->taskId = $taskId;
        $this->requestedAt = $requestedAt;
    }

    /**
     * Ответ модели: статья или «недостаточно данных» (`$categoryId` null).
     */
    public function suggest(?string $categoryId, float $confidence, string $verdict, ?string $model, ?string $promptVersion, \DateTimeImmutable $at): void
    {
        $this->assertStatus(Status::REQUESTED);
        if (null !== $categoryId) {
            Assert::uuid($categoryId);
        }
        Assert::range($confidence, 0, 1);

        $this->status = null === $categoryId ? Status::NO_ANSWER : Status::SUGGESTED;
        $this->categoryId = $categoryId;
        $this->confidence = number_format($confidence, 4, '.', '');
        $this->verdict = $verdict;
        $this->model = $model;
        $this->promptVersion = $promptVersion;
        $this->respondedAt = $at;
    }

    public function fail(string $errorCode, ?string $verdict, \DateTimeImmutable $at): void
    {
        $this->assertStatus(Status::REQUESTED);
        $this->status = Status::FAILED;
        $this->errorCode = mb_substr($errorCode, 0, 64);
        $this->verdict = $verdict;
        $this->respondedAt = $at;
    }

    public function markStale(string $reason, \DateTimeImmutable $at): void
    {
        $this->assertStatus(Status::REQUESTED, Status::SUGGESTED);
        $this->status = Status::STALE;
        $this->errorCode = mb_substr($reason, 0, 64);
        $this->respondedAt ??= $at;
    }

    public function accept(string $userId, \DateTimeImmutable $at): void
    {
        $this->decide(Status::ACCEPTED, $userId, $at);
    }

    public function dismiss(string $userId, \DateTimeImmutable $at): void
    {
        $this->decide(Status::DISMISSED, $userId, $at);
    }

    private function decide(Status $decision, string $userId, \DateTimeImmutable $at): void
    {
        $this->assertStatus(Status::SUGGESTED);
        Assert::uuid($userId);
        $this->status = $decision;
        $this->decidedByUserId = $userId;
        $this->decidedAt = $at;
    }

    private function assertStatus(Status ...$allowed): void
    {
        if (!\in_array($this->status, $allowed, true)) {
            throw CashCategorySuggestionStateException::fromStatus($this->status);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getCategoryId(): ?string
    {
        return $this->categoryId;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDecidedByUserId(): ?string
    {
        return $this->decidedByUserId;
    }
}
