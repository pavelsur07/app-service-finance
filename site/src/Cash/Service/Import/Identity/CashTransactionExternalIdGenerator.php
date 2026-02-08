<?php

namespace App\Cash\Service\Import\Identity;

use DateTimeInterface;

final class CashTransactionExternalIdGenerator
{
    public function generate(
        DateTimeInterface $occurredAt,
        float $amount,
        ?string $counterparty,
        ?string $description
    ): string {
        $normalizedParts = $this->normalizeParts(
            $occurredAt,
            $amount,
            $counterparty,
            $description
        );

        $sourceString = $this->buildSourceString($normalizedParts);

        return $this->hash($sourceString);
    }

    /**
     * @return array{
     *     date: string,
     *     amount: string,
     *     counterparty: string,
     *     description: string
     * }
     */
    private function normalizeParts(
        DateTimeInterface $occurredAt,
        float $amount,
        ?string $counterparty,
        ?string $description
    ): array {
        return [
            'date' => $this->normalizeDate($occurredAt),
            'amount' => $this->normalizeAmount($amount),
            'counterparty' => $this->normalizeOptionalText($counterparty),
            'description' => $this->normalizeOptionalText($description),
        ];
    }

    private function normalizeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    private function normalizeAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizeOptionalText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strtolower($value);
    }

    /**
     * @param array<string,string> $parts
     */
    private function buildSourceString(array $parts): string
    {
        return implode('|', [
            $parts['date'],
            $parts['amount'],
            $parts['counterparty'],
            $parts['description'],
        ]);
    }

    private function hash(string $source): string
    {
        return hash('sha256', $source);
    }
}
