<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Enum\Transaction\CashDirection;

class CashFileRowNormalizer
{
    /**
     * @param array<string, mixed> $rowByHeader
     * @param array<string, mixed> $mapping
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     occurredAt: ?\DateTimeImmutable,
     *     direction: ?CashDirection,
     *     amount: ?string,
     *     counterpartyName: ?string,
     *     description: ?string,
     *     currency: string,
     *     docNumber: ?string,
     *     raw: array<string, mixed>
     * }
     */
    public function normalize(array $rowByHeader, array $mapping, string $defaultCurrency): array
    {
        $errors = [];

        $occurredAt = $this->parseDate($this->getMappedValue($rowByHeader, $mapping['date'] ?? null));
        if (null === $occurredAt) {
            $errors[] = 'Не удалось распознать дату операции.';
        }

        [$direction, $amount, $amountErrors] = $this->resolveAmountAndDirection($rowByHeader, $mapping);
        $errors = array_merge($errors, $amountErrors);

        $counterpartyName = $this->getMappedValue($rowByHeader, $mapping['counterparty'] ?? null);
        $description = $this->getMappedValue($rowByHeader, $mapping['description'] ?? null);
        $docNumber = $this->getMappedValue($rowByHeader, $mapping['doc_number'] ?? null);

        [$currency, $currencyErrors] = $this->resolveCurrency(
            $rowByHeader,
            $mapping,
            $defaultCurrency
        );
        $errors = array_merge($errors, $currencyErrors);

        return [
            'ok' => [] === $errors,
            'errors' => $errors,
            'occurredAt' => $occurredAt,
            'direction' => $direction,
            'amount' => $amount,
            'counterpartyName' => $counterpartyName,
            'description' => $description,
            'currency' => $currency,
            'docNumber' => $docNumber,
            'raw' => $rowByHeader,
        ];
    }

    /**
     * @return array{0: ?CashDirection, 1: ?string, 2: list<string>}
     */
    private function resolveAmountAndDirection(array $rowByHeader, array $mapping): array
    {
        $errors = [];
        $direction = null;
        $amount = null;

        $amountColumn = $mapping['amount'] ?? null;
        if (null !== $amountColumn) {
            $amountValue = $this->parseNumber($this->getMappedValue($rowByHeader, $amountColumn));
            if (null === $amountValue) {
                $errors[] = 'Не удалось распознать сумму операции.';
            } else {
                $direction = $amountValue < 0 ? CashDirection::OUTFLOW : CashDirection::INFLOW;
                $amount = $this->formatAmount(abs($amountValue));
            }

            return [$direction, $amount, $errors];
        }

        $inflowValue = $this->parseNumber($this->getMappedValue($rowByHeader, $mapping['inflow'] ?? null));
        $outflowValue = $this->parseNumber($this->getMappedValue($rowByHeader, $mapping['outflow'] ?? null));

        if (null !== $inflowValue && $inflowValue > 0) {
            $direction = CashDirection::INFLOW;
            $amount = $this->formatAmount($inflowValue);
        } elseif (null !== $outflowValue && $outflowValue > 0) {
            $direction = CashDirection::OUTFLOW;
            $amount = $this->formatAmount($outflowValue);
        } else {
            $errors[] = 'Не удалось определить сумму операции.';
        }

        return [$direction, $amount, $errors];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function resolveCurrency(array $rowByHeader, array $mapping, string $defaultCurrency): array
    {
        $errors = [];
        $currencyValue = $this->getMappedValue($rowByHeader, $mapping['currency'] ?? null);
        if (null === $currencyValue) {
            return [$defaultCurrency, $errors];
        }

        $normalized = strtoupper(trim($currencyValue));
        if (3 !== strlen($normalized)) {
            $errors[] = 'Не удалось распознать валюту операции.';

            return [$defaultCurrency, $errors];
        }

        return [$normalized, $errors];
    }

    private function getMappedValue(array $rowByHeader, ?string $column): ?string
    {
        if (null === $column || !array_key_exists($column, $rowByHeader)) {
            return null;
        }

        $value = $rowByHeader[$column];
        if (null === $value) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return '' === $trimmed ? null : $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return null;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $trimmed);
            if (false === $date) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (0 === $errors['warning_count'] && 0 === $errors['error_count'])) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseNumber(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $trimmed);
        if (null === $normalized) {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if (false !== $lastComma && false !== $lastDot && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9\.\-]/', '', $normalized);
        if (null === $normalized || '' === $normalized || '-' === $normalized) {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
