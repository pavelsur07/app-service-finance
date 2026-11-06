<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\File;

/**
 * Pure allocation of file rows to an already company/account-scoped snapshot.
 *
 * @phpstan-type Row array{rowId: string, date: string, amount: string, direction: string, currency: string, docNumber: ?string, description: ?string, counterparty: ?string, ok?: bool}
 * @phpstan-type Candidate array{id: string, date: string, amount: string, direction: string, currency: string, docNumber: ?string, description: ?string, counterparty: ?string}
 * @phpstan-type Decision array{action: string, reason: string, transactionId: ?string, oldDescription: ?string}
 */
final readonly class CashFileDescriptionMatcher
{
    /**
     * @param list<Row>       $rows
     * @param list<Candidate> $candidates
     *
     * @return array<string, Decision>
     */
    public function plan(array $rows, array $candidates): array
    {
        $result = [];
        $valid = [];
        $available = [];
        $financial = [];
        $documents = [];
        $exact = [];
        foreach ($candidates as $index => $candidate) {
            $key = $this->financialKey($candidate);
            $financial[$key][] = $index;
            $available[$index] = true;
            $doc = trim((string) $candidate['docNumber']);
            if ('' !== $doc) {
                $documents[$candidate['date']][$doc][] = $index;
            }
            $exact[$key][$this->text($candidate['description'])][$doc][] = $index;
        }
        foreach ($rows as $row) {
            if (false === ($row['ok'] ?? true)) {
                $result[$row['rowId']] = $this->decision('invalid', 'invalid_row');
            } else {
                $valid[] = $row;
            }
        }

        // Preserve multiplicity and reserve numbered exact matches before blank-number fallbacks.
        // Empty incoming descriptions are processed last so they cannot steal a textual match.
        foreach ([false, true] as $emptyText) {
            foreach (['numbered', 'fallback', 'blank'] as $phase) {
                foreach ($valid as $row) {
                    if (isset($result[$row['rowId']]) || ('' === $this->text($row['description'])) !== $emptyText) {
                        continue;
                    }
                    $doc = trim((string) $row['docNumber']);
                    if (('blank' === $phase) !== ('' === $doc)) {
                        continue;
                    }
                    $key = $this->financialKey($row);
                    $textKeys = $emptyText ? array_keys($exact[$key] ?? []) : [$this->text($row['description'])];
                    foreach ($textKeys as $textKey) {
                        $docKeys = 'blank' === $phase ? array_keys($exact[$key][$textKey] ?? []) : [('fallback' === $phase) ? '' : $doc];
                        foreach ($docKeys as $docKey) {
                            foreach ($exact[$key][$textKey][$docKey] ?? [] as $offset => $index) {
                                if (!isset($available[$index])) {
                                    unset($exact[$key][$textKey][$docKey][$offset]);
                                    continue;
                                }
                                if (!$this->counterpartyCompatible($row, $candidates[$index])) {
                                    continue;
                                }
                                $result[$row['rowId']] = $this->decision('unchanged', $emptyText ? 'empty_description' : 'same_description', $candidates[$index]);
                                unset($available[$index], $exact[$key][$textKey][$docKey][$offset]);
                                break 3;
                            }
                        }
                    }
                }
            }
        }

        // Build the complete graph before assigning updates: never choose by ID or file order.
        $edges = [];
        $degrees = [];
        foreach ($valid as $row) {
            if (isset($result[$row['rowId']])) {
                continue;
            }
            foreach ($financial[$this->financialKey($row)] ?? [] as $index) {
                if (isset($available[$index]) && $this->documentCompatible($row, $candidates[$index]) && $this->counterpartyCompatible($row, $candidates[$index])) {
                    $edges[$row['rowId']][] = $index;
                    $degrees[$index] = ($degrees[$index] ?? 0) + 1;
                }
            }
        }
        foreach ($valid as $row) {
            $rowId = $row['rowId'];
            if (isset($result[$rowId])) {
                continue;
            }
            $options = $edges[$rowId] ?? [];
            if ([] !== $options) {
                if (1 === count($options) && 1 === $degrees[$options[0]]) {
                    $candidate = $candidates[$options[0]];
                    $result[$rowId] = $this->decision('update_description', 'possible_match', $candidate);
                } else {
                    $result[$rowId] = $this->decision('conflict', 'ambiguous_match');
                }
                continue;
            }

            $conflict = null;
            foreach ($financial[$this->financialKey($row)] ?? [] as $index) {
                $candidate = $candidates[$index];
                if (!$this->documentCompatible($row, $candidate)) {
                    continue;
                }
                if (!$this->counterpartyCompatible($row, $candidate)) {
                    $conflict = 'counterparty_mismatch';
                } elseif ('' !== $this->text($row['description']) && $this->text($row['description']) !== $this->text($candidate['description'])) {
                    $conflict = 'existing_match_exhausted';
                }
            }
            $doc = trim((string) $row['docNumber']);
            foreach ('' === $doc || [] !== ($financial[$this->financialKey($row)] ?? []) ? [] : ($documents[$row['date']][$doc] ?? []) as $index) {
                if ($candidates[$index]['amount'] !== $row['amount'] || $candidates[$index]['currency'] !== $row['currency'] || $candidates[$index]['direction'] !== $row['direction']) {
                    $conflict = 'financial_mismatch';
                }
            }
            $result[$rowId] = null === $conflict ? $this->decision('create', 'new_operation') : $this->decision('conflict', $conflict);
        }

        return $result;
    }

    public function text(?string $description): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $description ?? ''));
    }

    /** @param Row|Candidate $row */
    private function financialKey(array $row): string
    {
        return json_encode([$row['date'], $row['amount'], $row['direction'], $row['currency']], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param Row       $row
     * @param Candidate $candidate
     */
    private function documentCompatible(array $row, array $candidate): bool
    {
        $left = trim((string) $row['docNumber']);
        $right = trim((string) $candidate['docNumber']);

        return '' === $left || '' === $right || $left === $right;
    }

    /**
     * Counterparty values are normalized identities supplied by the caller.
     *
     * @param Row       $row
     * @param Candidate $candidate
     */
    private function counterpartyCompatible(array $row, array $candidate): bool
    {
        return null === $row['counterparty'] || null === $candidate['counterparty'] || $row['counterparty'] === $candidate['counterparty'];
    }

    /**
     * @param Candidate|null $candidate
     *
     * @return Decision
     */
    private function decision(string $action, string $reason, ?array $candidate = null): array
    {
        return ['action' => $action, 'reason' => $reason, 'transactionId' => $candidate['id'] ?? null, 'oldDescription' => $candidate['description'] ?? null];
    }
}
