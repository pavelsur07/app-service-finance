<?php

declare(strict_types=1);

namespace App\Tests\Builders\Telegram;

use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\PaymentIntakeSession;

final class PaymentIntakeSessionBuilder
{
    public const DEFAULT_DRAFT_ID = '0192f0a0-0000-7000-8000-00000000df01';

    private string $chatId = '123';
    private string $messageId = '456';

    private function __construct(private ClientBinding $binding, private string $draftId)
    {
    }

    public static function forBinding(ClientBinding $binding, string $draftId = self::DEFAULT_DRAFT_ID): self
    {
        return new self($binding, $draftId);
    }

    public function withMessageIdentity(string $chatId, string $messageId): self
    {
        $clone = clone $this;
        $clone->chatId = $chatId;
        $clone->messageId = $messageId;

        return $clone;
    }

    public function build(): PaymentIntakeSession
    {
        return new PaymentIntakeSession($this->binding, $this->chatId, $this->messageId, $this->draftId);
    }
}
