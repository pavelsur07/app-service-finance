<?php

declare(strict_types=1);

namespace App\Telegram\Application\DTO;

final readonly class CreateTelegramCashTransactionCommand
{
    public function __construct(
        public ?string $botId,
        public string $companyId,
        public string $moneyAccountId,
        public string $currency,
        public ?string $chatId,
        public ?string $messageId,
        public ?string $fromId,
        public ?int $updateId,
        public ?int $messageDate,
        public string $text,
    ) {}
}
