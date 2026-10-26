<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementLineDTO;
use App\BankSync\Enum\BankDirection;

/**
 * Разбор ответов Альфы: список счетов (Open Banking) и страница выписки
 * `GET /jp/v1/statement/transactions`. Формат Альфы дальше адаптера не выходит.
 */
final readonly class AlfaStatementMapper
{
    /**
     * Номера счетов из `Data.Account[].AccountDetails[].identification`, без повторов.
     *
     * @param array<mixed> $response
     *
     * @return list<BankAccountDTO>
     */
    public function mapAccounts(array $response): array
    {
        $data = $response['Data'] ?? null;
        $accounts = \is_array($data) ? ($data['Account'] ?? null) : null;
        if (!\is_array($accounts)) {
            return [];
        }

        $numbers = [];
        foreach ($accounts as $account) {
            $details = \is_array($account) ? ($account['AccountDetails'] ?? null) : null;
            if (!\is_array($details)) {
                continue;
            }

            foreach ($details as $detail) {
                $identification = \is_array($detail) ? ($detail['identification'] ?? null) : null;
                if (\is_string($identification) && '' !== trim($identification)) {
                    $numbers[trim($identification)] = true;
                }
            }
        }

        return array_map(
            static fn (int|string $number): BankAccountDTO => new BankAccountDTO((string) $number),
            array_keys($numbers),
        );
    }

    /**
     * @param array<mixed> $response
     */
    public function mapStatementPage(array $response): AlfaStatementPage
    {
        $transactions = $response['transactions'] ?? null;
        $lines = [];
        $skipped = 0;

        foreach (\is_array($transactions) ? $transactions : [] as $transaction) {
            $line = \is_array($transaction) ? $this->mapLine($transaction) : null;
            if (null === $line) {
                ++$skipped;
                continue;
            }

            $lines[] = $line;
        }

        return new AlfaStatementPage($lines, $skipped, $this->hasNextPage($response));
    }

    /**
     * @param array<mixed> $transaction
     */
    private function mapLine(array $transaction): ?BankStatementLineDTO
    {
        $externalId = $this->nonEmptyString($transaction['uuid'] ?? null);
        $occurredAt = $this->resolveOccurredAt($transaction);
        $amountBlock = \is_array($transaction['amount'] ?? null) ? $transaction['amount'] : [];
        $amount = $this->resolveAmount($amountBlock['amount'] ?? null);
        $direction = match ($transaction['direction'] ?? null) {
            'DEBIT' => BankDirection::DEBIT,
            'CREDIT' => BankDirection::CREDIT,
            default => null,
        };

        if (null === $externalId || null === $occurredAt || null === $amount || null === $direction) {
            return null;
        }

        $currency = $this->nonEmptyString($amountBlock['currencyName'] ?? null);
        if (null !== $currency) {
            $currency = strtoupper($currency);
            // RUR — код рубля до деноминации 1998 года, банки иногда присылают его; счёт в ДДС — RUB.
            $currency = 'RUR' === $currency ? 'RUB' : $currency;
        }

        return new BankStatementLineDTO(
            externalId: $externalId,
            direction: $direction,
            amount: $amount,
            currency: $currency,
            occurredAt: $occurredAt,
            description: $this->nonEmptyString($transaction['paymentPurpose'] ?? null),
            transactionId: $this->nonEmptyString($transaction['transactionId'] ?? null),
        );
    }

    /**
     * `operationDate` с временем; если его нет — начало дня `documentDate`.
     *
     * @param array<mixed> $transaction
     */
    private function resolveOccurredAt(array $transaction): ?\DateTimeImmutable
    {
        $operationDate = $this->nonEmptyString($transaction['operationDate'] ?? null);
        if (null !== $operationDate) {
            return $this->parseDate($operationDate);
        }

        $documentDate = $this->nonEmptyString($transaction['documentDate'] ?? null);
        if (null === $documentDate) {
            return null;
        }

        return $this->parseDate($documentDate)?->setTime(0, 0);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Сумма без знака с двумя знаками после точки. Строку нормализуем без float; число из
     * JSON уже пришло float-ом — его только форматируем.
     */
    private function resolveAmount(mixed $raw): ?string
    {
        if (\is_int($raw)) {
            $raw = (string) $raw;
        }

        if (\is_string($raw)) {
            $raw = trim($raw);

            return 1 === preg_match('/^\d+(\.\d+)?$/', $raw) ? bcadd($raw, '0', 2) : null;
        }

        if (\is_float($raw) && is_finite($raw) && $raw >= 0) {
            return number_format($raw, 2, '.', '');
        }

        return null;
    }

    /**
     * @param array<mixed> $response
     */
    private function hasNextPage(array $response): bool
    {
        $links = $response['_links'] ?? null;
        if (!\is_array($links)) {
            return false;
        }

        foreach ($links as $link) {
            if (\is_array($link) && 'next' === ($link['rel'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }
}
