<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use App\Cash\Entity\PaymentIntake\PaymentIntakeReplyReceipt;

final class PaymentIntakeReplyReceiptBuilder
{
    private string $replyKey;
    private ?string $id = null;

    private function __construct(private PaymentIntakeDraft $draft, private string $itemId, private int $revision)
    {
        $this->replyKey = hash('sha256', 'telegram:test:reply');
    }

    public static function forDraft(PaymentIntakeDraft $draft, string $itemId, int $revision = 1): self
    {
        return new self($draft, $itemId, $revision);
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withReplyKey(string $replyKey): self
    {
        $clone = clone $this;
        $clone->replyKey = $replyKey;

        return $clone;
    }

    public function build(): PaymentIntakeReplyReceipt
    {
        return new PaymentIntakeReplyReceipt($this->replyKey, $this->draft, $this->itemId, $this->revision, new \DateTimeImmutable('2026-11-13T12:00:00+03:00'), $this->id);
    }
}
