<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Telegram\Application\DTO\CreateTelegramCashTransactionActionResult;
use App\Telegram\Application\DTO\CreateTelegramCashTransactionCommand;
use App\Telegram\Domain\Service\TelegramCashTransactionExternalIdGenerator;

final readonly class CreateTelegramCashTransactionAction
{
    public function __construct(
        private CashFacade $cashFacade,
        private TelegramCashTransactionExternalIdGenerator $externalIdGenerator,
    ) {}

    public function __invoke(CreateTelegramCashTransactionCommand $command): ?CreateTelegramCashTransactionActionResult
    {
        $amount = $this->parseAmountFromText($command->text);
        if (null === $amount) {
            return null;
        }

        if (null === $command->chatId || null === $command->messageId) {
            return CreateTelegramCashTransactionActionResult::skippedDueToMissingMessageIdentity();
        }

        $direction = $this->detectDirection($command->text);
        $externalId = $this->externalIdGenerator->generate($command->botId, $command->chatId, $command->messageId);

        $result = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $command->companyId,
            moneyAccountId: $command->moneyAccountId,
            direction: $direction,
            amount: $amount,
            currency: $command->currency,
            occurredAt: new \DateTimeImmutable(),
            description: $command->text,
            importSource: 'telegram',
            externalId: $externalId,
            rawData: [
                'source' => 'telegram',
                'update_id' => $command->updateId,
                'message_id' => $command->messageId,
                'chat_id' => $command->chatId,
                'from_id' => $command->fromId,
                'message_date' => $command->messageDate,
                'text' => $command->text,
                'bot_id_fallback' => null === $command->botId ? 'default' : null,
            ],
        ));

        return CreateTelegramCashTransactionActionResult::created(
            duplicate: $result->duplicate,
            amount: $amount,
            directionLabel: CashDirection::INFLOW === $direction ? 'доход' : 'расход',
        );
    }

    private function parseAmountFromText(string $text): ?string
    {
        if (!preg_match('/\d[\d\s.,]*/u', $text, $matches)) {
            return null;
        }

        $raw = preg_replace('/\s+/u', '', $matches[0]);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');
        $decimalSeparator = null;

        if (false !== $lastDot && false !== $lastComma) {
            $decimalSeparator = $lastDot > $lastComma ? '.' : ',';
        } elseif (false !== $lastDot) {
            $decimalSeparator = '.';
        } elseif (false !== $lastComma) {
            $decimalSeparator = ',';
        }

        if (',' === $decimalSeparator) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }

        [$integerPart, $fractionalPart] = array_pad(explode('.', $raw, 2), 2, '');
        $integerPart = ltrim($integerPart, '0');
        $integerPart = '' === $integerPart ? '0' : $integerPart;
        $fractionalPart = substr($fractionalPart, 0, 2);
        $fractionalPart = str_pad($fractionalPart, 2, '0');

        return sprintf('%s.%s', $integerPart, $fractionalPart);
    }

    private function detectDirection(string $text): CashDirection
    {
        $normalized = mb_strtolower($text);

        if (preg_match('/потрат|расход|купил|оплат/u', $normalized)) {
            return CashDirection::OUTFLOW;
        }

        if (preg_match('/пришло|поступ|доход|получил/u', $normalized)) {
            return CashDirection::INFLOW;
        }

        return CashDirection::OUTFLOW;
    }
}
