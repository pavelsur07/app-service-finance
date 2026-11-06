<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Принятый результат ai-service: токены и стоимость в ₽ (null — цена модели неизвестна).
 * Пишет `ImportAiResultsAction` через DBAL в транзакции потребителя, читает `AiUsageQuery`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage')]
#[ORM\UniqueConstraint(name: 'uq_ai_usage_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_ai_usage_company_completed', columns: ['company_id', 'completed_at'])]
class AiUsage
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $taskId;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $taskType;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $model;

    #[ORM\Column(type: 'integer')]
    private int $inputTokens;

    #[ORM\Column(type: 'integer')]
    private int $outputTokens;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $costRub;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $completedAt;

    public function __construct(
        string $taskId,
        string $companyId,
        string $taskType,
        string $status,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        ?string $costRub,
        \DateTimeImmutable $completedAt,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);
        Assert::uuid($taskId);
        Assert::uuid($companyId);
        Assert::greaterThanEq($inputTokens, 0);
        Assert::greaterThanEq($outputTokens, 0);

        $this->id = $id;
        $this->taskId = $taskId;
        $this->companyId = $companyId;
        $this->taskType = $taskType;
        $this->status = $status;
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->costRub = $costRub;
        $this->completedAt = $completedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
