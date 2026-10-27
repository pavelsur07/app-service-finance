<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementLineDTO;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;

/**
 * Разбор ответов Альфы: список счетов (Open Banking) и страница выписки
 * `GET /jp/v1/statement/transactions`. Формат Альфы дальше адаптера не выходит.
 */
final readonly class AlfaStatementMapper
{
    /**
     * Счета из ответа `open-banking/v3.0/aisp-le/accounts`: номер — первый
     * `AccountDetails[]` со схемой `RU.CBR.BBAN`, БИК — `Servicer.BankIdentification[]` со
     * схемой `RU.CBR.BIC`. Повтор номера — один счёт. Владелец счёта (наименование, ИНН)
     * не читается.
     *
     * @param array<mixed> $response
     *
     * @throws BankApiRequestFailedException нет `Data`, `Data.Account` не список или счетов
     *                                       больше одной страницы (параметра страницы у
     *                                       метода нет — часть счетов потерялась бы молча)
     */
    public function mapAccounts(array $response): AlfaAccountList
    {
        $data = $response['Data'] ?? null;
        $accounts = \is_array($data) ? ($data['Account'] ?? []) : null;
        $totalPages = \is_array($response['Meta'] ?? null) ? ($response['Meta']['totalPages'] ?? 1) : 1;
        if (!\is_array($accounts) || !array_is_list($accounts) || !\is_int($totalPages) || $totalPages > 1) {
            throw new BankApiRequestFailedException(BankCode::ALFA->value, null);
        }

        $mapped = [];
        $skipped = 0;
        foreach ($accounts as $account) {
            $dto = \is_array($account) ? $this->mapAccount($account) : null;
            if (null === $dto) {
                ++$skipped;
                continue;
            }
            $mapped[$dto->accountNumber] ??= $dto;
        }

        return new AlfaAccountList(array_values($mapped), $skipped);
    }

    /**
     * @param array<mixed> $account
     */
    private function mapAccount(array $account): ?BankAccountDTO
    {
        $number = $this->identification($account['AccountDetails'] ?? null, 'RU.CBR.BBAN');
        $currency = $this->nonEmptyString($account['currency'] ?? null);
        if (null === $number || null === $currency) {
            return null;
        }
        $currency = strtoupper($currency);
        $servicer = $account['Servicer'] ?? null;

        return new BankAccountDTO(
            accountNumber: $number,
            currency: 'RUR' === $currency ? 'RUB' : $currency,
            enabled: 'Enabled' === ($account['status'] ?? null),
            bic: $this->identification(\is_array($servicer) ? ($servicer['BankIdentification'] ?? null) : null, 'RU.CBR.BIC'),
            description: $this->nonEmptyString($account['accountDescription'] ?? null),
            externalId: $this->nonEmptyString($account['accountId'] ?? null),
        );
    }

    private function identification(mixed $identifications, string $scheme): ?string
    {
        if (!\is_array($identifications)) {
            return null;
        }
        foreach ($identifications as $identification) {
            if (\is_array($identification) && $scheme === ($identification['schemeName'] ?? null)) {
                $value = $this->nonEmptyString($identification['identification'] ?? null);
                if (null !== $value) {
                    return $value;
                }
            }
        }

        return null;
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
