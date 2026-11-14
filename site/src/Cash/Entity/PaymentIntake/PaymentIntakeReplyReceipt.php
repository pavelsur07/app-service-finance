<?php

declare(strict_types=1);

namespace App\Cash\Entity\PaymentIntake;

use App\Cash\Repository\PaymentIntake\PaymentIntakeReplyReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/** UUIDv7 receipt with a globally unique SHA256 channel reply identity. */
#[ORM\Entity(repositoryClass: PaymentIntakeReplyReceiptRepository::class)]
#[ORM\Table(name: 'cash_payment_intake_reply_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_payment_intake_reply_key', columns: ['reply_key'])]
#[ORM\Index(name: 'idx_payment_intake_reply_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_payment_intake_reply_draft', columns: ['draft_id'])]
class PaymentIntakeReplyReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;
    #[ORM\Column(length: 64)]
    private string $replyKey;
    #[ORM\Column(type: Types::GUID)]
    private string $companyId;
    #[ORM\Column(type: Types::GUID)]
    private string $actorUserId;
    #[ORM\ManyToOne(targetEntity: PaymentIntakeDraft::class)]
    #[ORM\JoinColumn(name: 'draft_id', nullable: false, onDelete: 'CASCADE')]
    private PaymentIntakeDraft $draft;
    #[ORM\Column(type: Types::GUID)]
    private string $itemId;
    #[ORM\Column]
    private int $originalRevision;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $replyKey, PaymentIntakeDraft $draft, string $itemId, int $originalRevision, \DateTimeImmutable $createdAt, ?string $id = null)
    {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::regex($replyKey, '/^[0-9a-f]{64}$/D');
        Assert::uuid($itemId);
        Assert::positiveInteger($originalRevision);
        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->replyKey = $replyKey;
        $this->companyId = $draft->getCompanyId();
        $this->actorUserId = $draft->getActorUserId();
        $this->draft = $draft;
        $this->itemId = $itemId;
        $this->originalRevision = $originalRevision;
        $this->createdAt = $createdAt;
    }
}
