<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\File;

use App\Cash\Exception\InvalidCashFileMappingException;

final readonly class CashFileMappingValidator
{
    public const FIELDS = ['date', 'amount', 'inflow', 'outflow', 'counterparty', 'description', 'currency', 'doc_number'];

    /** @param array<array-key, mixed> $mapping */
    public function validate(array $mapping): void
    {
        foreach ($mapping as $key => $value) {
            if (!in_array($key, self::FIELDS, true) || (null !== $value && !is_string($value))) {
                throw new InvalidCashFileMappingException();
            }
        }
    }

    /** Drafts remain structurally valid; importing requires a complete configuration.
     * @param array<array-key, mixed> $mapping
     */
    public function validateForImport(array $mapping): void
    {
        $this->validate($mapping);
        $has = static fn (string $key): bool => is_string($mapping[$key] ?? null) && '' !== trim($mapping[$key]);
        if (!$has('date')) {
            throw new InvalidCashFileMappingException('Укажите колонку с датой операции перед импортом.');
        }
        if ($has('inflow') !== $has('outflow')) {
            throw new InvalidCashFileMappingException('Выберите обе колонки приход/расход или общую сумму перед импортом.');
        }
        if (!$has('amount') && !$has('inflow')) {
            throw new InvalidCashFileMappingException('Укажите колонку суммы или пару колонок приход/расход перед импортом.');
        }
    }
}
