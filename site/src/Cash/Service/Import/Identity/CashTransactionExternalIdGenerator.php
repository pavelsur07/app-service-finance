<?php

namespace App\Cash\Service\Import\Identity;

use DateTimeInterface;

final class CashTransactionExternalIdGenerator
{
    public function generate(
        DateTimeInterface $occurredAt,
        float $amount,
        ?string $counterparty,
        ?string $description,
        ?int $rowIndex = null
    ): string {
        $normalizedParts = $this->normalizeParts(
            $occurredAt,
            $amount,
            $counterparty,
            $description,
            $rowIndex
        );

        $sourceString = $this->buildSourceString($normalizedParts);

        return $this->hash($sourceString);
    }

    /**
     * @return array{
     *     date: string,
     *     amount: string,
     *     counterparty: string,
     *     description: string,
     *     rowIndex: string
     * }
     */
    private function normalizeParts(
        DateTimeInterface $occurredAt,
        float $amount,
        ?string $counterparty,
        ?string $description,
        ?int $rowIndex
    ): array {
        $counterpartyNorm = $this->normalizeOptionalText($counterparty);
        $descriptionNorm = $this->normalizeOptionalText($description);

        $needsRowIndex = ($counterpartyNorm === '' && $descriptionNorm === '');
        $rowIndexNorm = $needsRowIndex ? $this->normalizeRowIndex($rowIndex) : '';

        return [
            'date' => $this->normalizeDate($occurredAt),
            'amount' => $this->normalizeAmount($amount),
            'counterparty' => $counterpartyNorm,
            'description' => $descriptionNorm,
            'rowIndex' => $rowIndexNorm,
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

    private function normalizeRowIndex(?int $rowIndex): string
    {
        // Важно: если rowIndex почему-то не передали — оставляем пусто.
        // Но тогда останутся коллизии. Лучше обеспечь передачу rowIndex всегда.
        if ($rowIndex === null || $rowIndex < 0) {
            return '';
        }

        return (string) $rowIndex;
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
            $parts['rowIndex'],
        ]);
    }

    private function hash(string $source): string
    {
        return hash('sha256', $source);
    }
}
