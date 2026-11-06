<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Запуск ИИ по компании (cron `ai-categorize`): итог или причина пропуска, класс исключения
 * при сбое. Пишет и читает `AiRunJournal` через DBAL; хранится 90 дней.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_run')]
#[ORM\Index(name: 'idx_ai_run_company_ran', columns: ['company_id', 'ran_at'])]
class AiRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $taskType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $ranAt;

    #[ORM\Column(type: 'integer')]
    private int $requested;

    #[ORM\Column(type: 'integer')]
    private int $tasks;

    #[ORM\Column(type: 'integer')]
    private int $redispatched;

    #[ORM\Column(type: 'integer')]
    private int $expired;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $skippedReason;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $error;

    public function __construct(
        string $companyId,
        string $taskType,
        \DateTimeImmutable $ranAt,
        int $requested,
        int $tasks,
        int $redispatched,
        int $expired,
        ?string $skippedReason,
        ?string $error,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->taskType = $taskType;
        $this->ranAt = $ranAt;
        $this->requested = $requested;
        $this->tasks = $tasks;
        $this->redispatched = $redispatched;
        $this->expired = $expired;
        $this->skippedReason = $skippedReason;
        $this->error = $error;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
