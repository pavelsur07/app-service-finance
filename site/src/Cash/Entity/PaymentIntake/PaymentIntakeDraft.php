<?php

declare(strict_types=1);

namespace App\Cash\Entity\PaymentIntake;

use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PaymentIntakeDraftRepository::class)]
#[ORM\Table(name: 'cash_payment_intake_draft')]
#[ORM\UniqueConstraint(name: 'uniq_payment_intake_mcp_external', columns: ['company_id', 'mcp_connection_id', 'mcp_external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_intake_source', columns: ['source_key'])]
#[ORM\Index(name: 'idx_payment_intake_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_payment_intake_task', columns: ['company_id', 'task_id'])]
class PaymentIntakeDraft
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;
    #[ORM\Column(type: Types::GUID)]
    private string $companyId;
    #[ORM\Column(type: Types::GUID)]
    private string $actorUserId;
    #[ORM\Column(type: Types::GUID)]
    private string $moneyAccountId;
    #[ORM\Column(length: 255)]
    private string $sourceKey;
    #[ORM\Column(length: 64)]
    private string $sourceRevision;
    #[ORM\Column(type: Types::TEXT)]
    private string $text;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;
    #[ORM\Column(length: 64)]
    private string $timezone;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column]
    private int $revision = 1;
    #[ORM\Column]
    private int $attempt = 1;
    #[ORM\Column]
    private int $taskRevision = 1;
    #[ORM\Column(length: 24)]
    private string $status = 'pending';
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $taskId = null;
    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSONB)]
    private array $items = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $questions = [];
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $payload = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $transactionIds = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $confirmedItemIds = [];
    #[ORM\Column(nullable: true)]
    private ?int $confirmedRevision = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;
    #[ORM\Column]
    private bool $dispatchPending = true;
    #[ORM\Column]
    private bool $cashEventPending = false;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastEnqueuedAt = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $mcpConnectionId = null;
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mcpExternalId = null;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(string $companyId, string $actorUserId, string $moneyAccountId, string $sourceKey, string $sourceRevision, string $text, \DateTimeImmutable $receivedAt, string $timezone, string $currency)
    {
        Assert::uuid($companyId);
        Assert::uuid($actorUserId);
        Assert::uuid($moneyAccountId);
        Assert::maxLength($sourceKey, 255);
        Assert::maxLength($sourceRevision, 64);
        Assert::notEmpty(trim($text));
        Assert::maxLength($text, 16000);
        new \DateTimeZone($timezone);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->actorUserId = $actorUserId;
        $this->moneyAccountId = $moneyAccountId;
        $this->sourceKey = $sourceKey;
        $this->sourceRevision = $sourceRevision;
        $this->text = $text;
        $this->receivedAt = $receivedAt;
        $this->timezone = $timezone;
        $this->currency = $currency;
    }

    public static function fromMcp(CreateMcpCashDraft $command, string $currency, string $fingerprint, \DateTimeImmutable $receivedAt): self
    {
        $draft = new self($command->companyId, $command->actorUserId, $command->moneyAccountId, 'mcp:'.Uuid::uuid7()->toString(), $fingerprint, 'MCP', $receivedAt, 'UTC', $currency);
        $draft->mcpConnectionId = strtolower($command->connectionId);
        $draft->mcpExternalId = $command->externalId;
        $draft->dispatchPending = false;
        $draft->status = 'needs_clarification';
        $draft->items = [[
            'id' => Uuid::uuid7()->toString(),
            'transaction_id' => Uuid::uuid7()->toString(),
            'date' => $command->date->format('Y-m-d'),
            'direction' => strtolower($command->direction->value),
            'amount' => $command->amount,
            'currency' => $currency,
            'category_id' => null === $command->categoryId ? null : strtolower($command->categoryId),
            'description' => $command->description,
            'project_id' => null,
            'cfo_id' => null,
            'paid_confirmed' => false,
            'execution_status' => 'unknown',
            'questions' => [],
            'provenance' => ['amount' => 'mcp', 'date' => 'mcp', 'direction' => 'mcp'],
        ]];

        return $draft;
    }

    public function isMcp(): bool
    {
        return null !== $this->mcpConnectionId;
    }

    public function getMcpConnectionId(): ?string
    {
        return $this->mcpConnectionId;
    }

    public function getMcpExternalId(): ?string
    {
        return $this->mcpExternalId;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirmMcpPayment(int $expectedRevision, \DateTimeImmutable $confirmedAt): void
    {
        $this->assertMutable($expectedRevision);
        Assert::true($this->isMcp());
        $this->items[0]['paid_confirmed'] = true;
        $this->items[0]['execution_status'] = 'paid';
        $this->items[0]['provenance']['execution_status'] = 'user';
        $this->confirmedAt = $confirmedAt;
    }

    /**
     * @param list<string> $itemIds
     *
     * @return list<array<string, mixed>>
     */
    public function selectedForConfirmation(int $expectedRevision, array $itemIds): array
    {
        $this->assertMutable($expectedRevision);
        if (!\in_array($this->status, ['ready', 'needs_clarification'], true) || [] !== $this->questions || [] === $itemIds || \count($itemIds) !== \count(array_unique($itemIds))) {
            throw new \DomainException('Сначала ответьте на вопросы и выберите платежи.');
        }
        foreach ($this->items as $item) {
            if (!($item['excluded'] ?? false) && !\in_array($item['id'], $itemIds, true)) {
                throw new \DomainException('Выберите все неисключённые платежи или явно исключите оставшиеся строки.');
            }
        }
        $selected = [];
        foreach ($itemIds as $id) {
            $matches = array_filter($this->items, static fn (array $item): bool => $item['id'] === $id);
            $item = array_values($matches)[0] ?? null;
            if (null === $item || ($item['excluded'] ?? false) || true !== ($item['paid_confirmed'] ?? false) || 'paid' !== $item['execution_status'] || [] !== $item['questions']) {
                throw new \DomainException('Подтвердите факт оплаты и разрешите вопросы каждой строки.');
            }
            $selected[] = $item;
        }

        return $selected;
    }

    /**
     * @param list<string> $itemIds
     * @param list<string> $transactionIds
     */
    public function confirm(int $expectedRevision, array $itemIds, array $transactionIds): void
    {
        $this->assertMutable($expectedRevision);
        $this->confirmedRevision = $expectedRevision;
        $this->confirmedItemIds = $itemIds;
        $this->transactionIds = $transactionIds;
        $this->status = 'confirmed';
        $this->payload = [];
        $this->dispatchPending = false;
        $this->cashEventPending = true;
    }

    /** @param list<string> $itemIds */
    public function confirmationMatches(int $revision, array $itemIds): bool
    {
        $actual = $this->confirmedItemIds;
        sort($actual);
        sort($itemIds);

        return 'confirmed' === $this->status && $revision === $this->confirmedRevision && $actual === $itemIds;
    }

    public function cancel(int $expectedRevision): void
    {
        if ('cancelled' === $this->status && $expectedRevision === $this->revision) {
            return;
        }
        $this->assertMutable($expectedRevision);
        $this->status = 'cancelled';
        $this->payload = [];
        $this->dispatchPending = false;
    }

    private function assertMutable(int $expectedRevision): void
    {
        if ($expectedRevision !== $this->revision || \in_array($this->status, ['confirmed', 'cancelled'], true)) {
            throw new \DomainException('Черновик уже изменён или закрыт. Обновите карточку.');
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

    public function getActorUserId(): string
    {
        return $this->actorUserId;
    }

    public function getMoneyAccountId(): string
    {
        return $this->moneyAccountId;
    }

    public function getSourceRevision(): string
    {
        return $this->sourceRevision;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return list<array<string, mixed>> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return list<string> */
    public function getTransactionIds(): array
    {
        return $this->transactionIds;
    }

    public function isCashEventPending(): bool
    {
        return $this->cashEventPending;
    }

    public function acknowledgeCashEvent(): void
    {
        $this->cashEventPending = false;
    }
}
