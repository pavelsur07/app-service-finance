<?php

declare(strict_types=1);

namespace App\Telegram\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'telegram_payment_intake_session')]
#[ORM\Index(name: 'idx_tg_intake_company_recovery', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_telegram_intake_source', columns: ['bot_id', 'chat_id', 'message_id'])]
class PaymentIntakeSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ClientBinding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ClientBinding $binding;

    #[ORM\Column(type: 'guid')]
    private string $botId;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $chatId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $messageId;

    #[ORM\Column(type: 'guid')]
    private string $draftId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $sourceRevision = 'original';

    #[ORM\Column(type: 'string', length: 100)]
    private string $deliveredState = '';

    #[ORM\Column(type: 'boolean')]
    private bool $deliveryPending = true;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $deliveryRequestToken = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $replyMessageIds = [];

    #[ORM\Column(type: 'integer')]
    private int $deliveryCursor = 0;

    #[ORM\Column(type: 'string', length: 100)]
    private string $sendingState = '';

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $editingItemId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $editingRevision = null;

    #[ORM\Column(type: 'integer')]
    private int $lastSequence = -1;

    /** @var array{attempt:int, sequence:int, delay:int}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pendingPoll = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $pollGeneration = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pollUntil = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $leaseUntil = null;

    public function __construct(ClientBinding $binding, string $chatId, string $messageId, string $draftId)
    {
        Assert::uuid($draftId);
        $this->id = Uuid::uuid7()->toString();
        $this->binding = $binding;
        $this->botId = (string) $binding->getBot()->getId();
        $this->companyId = (string) $binding->getCompany()->getId();
        $this->chatId = $chatId;
        $this->messageId = $messageId;
        $this->draftId = $draftId;
        $this->deliveryRequestToken = Uuid::uuid7()->toString();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getBinding(): ClientBinding
    {
        return $this->binding;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function getDraftId(): string
    {
        return $this->draftId;
    }

    public function getSourceRevision(): string
    {
        return $this->sourceRevision;
    }

    public function setSourceRevision(string $revision): void
    {
        $this->sourceRevision = $revision;
    }

    public function awaitEdit(string $itemId, int $revision): void
    {
        $this->editingItemId = $itemId;
        $this->editingRevision = $revision;
    }

    public function getEditingItemId(): ?string
    {
        return $this->editingItemId;
    }

    public function getEditingRevision(): ?int
    {
        return $this->editingRevision;
    }

    public function clearEdit(): void
    {
        $this->editingItemId = null;
        $this->editingRevision = null;
    }

    public function requireDelivery(): void
    {
        $this->deliveryPending = true;
        $this->deliveryRequestToken = Uuid::uuid7()->toString();
        $this->deliveryCursor = 0;
        $this->sendingState = '';
    }

    public function getDeliveryRequestToken(): ?string
    {
        return $this->deliveryRequestToken;
    }

    public function rememberReplyMessage(?string $messageId): void
    {
        if (null !== $messageId) {
            $this->replyMessageIds = array_values(array_unique([...$this->replyMessageIds, $messageId]));
        }
    }

    public function needsDelivery(string $state): bool
    {
        return $this->deliveryPending || $this->deliveredState !== $state;
    }

    public function deliveryCursor(string $state): int
    {
        return $this->sendingState === $state ? $this->deliveryCursor : 0;
    }

    public function recordDeliveredPart(string $state, ?string $messageId): void
    {
        $cursor = $this->deliveryCursor($state);
        $this->sendingState = $state;
        $this->deliveryCursor = $cursor + 1;
        $this->rememberReplyMessage($messageId);
    }

    /** @param list<string> $messageIds */
    public function markDelivered(string $state, array $messageIds): void
    {
        $this->deliveredState = $state;
        $this->replyMessageIds = array_values(array_unique([...$this->replyMessageIds, ...$messageIds]));
        $this->deliveryPending = false;
    }

    public function schedulePolling(): ?string
    {
        $now = new \DateTimeImmutable();
        if (null !== $this->pollUntil && $this->pollUntil > $now) {
            return null;
        }
        $this->lastSequence = -1;
        $this->pollGeneration = Uuid::uuid7()->toString();
        $this->pollUntil = $now->modify('+2 minutes');
        $this->preparePoll(0, 0, 0);

        return $this->pollGeneration;
    }

    /** @return array{attempt:int, sequence:int, delay:int}|null */
    public function pendingPoll(): ?array
    {
        return $this->pendingPoll;
    }

    public function getPollGeneration(): ?string
    {
        return $this->pollGeneration;
    }

    public function preparePoll(int $attempt, int $sequence, int $delay): void
    {
        $this->pendingPoll = ['attempt' => $attempt, 'sequence' => $sequence, 'delay' => $delay];
    }

    public function recordSequence(int $sequence): void
    {
        $this->lastSequence = $sequence;
    }

    public function pollingMatches(string $generation): bool
    {
        return $this->pollGeneration === $generation;
    }

    public function finishPolling(): void
    {
        $this->pollGeneration = null;
        $this->pollUntil = null;
        $this->pendingPoll = null;
    }

    public function acceptsReply(string $messageId): bool
    {
        return in_array($messageId, $this->replyMessageIds, true);
    }
}
