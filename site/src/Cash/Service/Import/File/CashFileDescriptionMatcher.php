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
     * @param list<Row> $rows
     * @param list<Candidate> $candidates
     *
     * @return array<string, Decision>
     */
    public function plan(array $rows, array $candidates): array
    {
        $result = [];
        $valid = [];
        $numberedClaims = [];
        $partyClaims = [];
        $rowsById = [];
        $meaningfulRows = [];
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
                $key = $this->financialKey($row);
                $rowsById[$row['rowId']] = $row;
                if ('' !== $this->text($row['description'])) {
                    $meaningfulRows[$key][] = $row;
                }
                if (null !== $row['counterparty']) {
                    $partyClaims[$key][$row['counterparty']][] = $row['rowId'];
                }
                $doc = trim((string) $row['docNumber']);
                if ('' !== $doc) {
                    $numberedClaims[$this->financialKey($row)][$doc][] = $row['rowId'];
                }
            }
        }

        // Preserve multiplicity and reserve numbered exact matches before blank-number fallbacks.
        // Empty incoming descriptions are processed last so they cannot steal a textual match.
        foreach ([false, true] as $emptyText) {
            foreach (['numbered', 'fallback', 'blank'] as $phase) {
                foreach (['exact_party', 'fallback_party', 'blank_party'] as $partyPhase) {
                    foreach ($valid as $row) {
                        if (isset($result[$row['rowId']]) || ('' === $this->text($row['description'])) !== $emptyText) {
                            continue;
                        }
                        if (('blank_party' === $partyPhase) !== (null === $row['counterparty'])) {
                            continue;
                        }
                        $doc = trim((string) $row['docNumber']);
                        if (('blank' === $phase) !== ('' === $doc)) {
                            continue;
                        }
                        $key = $this->financialKey($row);
                        if ($emptyText && $this->emptyDescriptionCompetesWithUpdate($row, $meaningfulRows[$key] ?? [], $financial[$key] ?? [], $candidates, $available, $result)) {
                            // No description can identify which snapshot an empty-text row consumes.
                            // Leave every candidate available to the remaining update graph.
                            $result[$row['rowId']] = $this->decision('conflict', 'ambiguous_match');
                            continue;
                        }
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
                                    if (('exact_party' === $partyPhase && $candidates[$index]['counterparty'] !== $row['counterparty'])
                                        || ('fallback_party' === $partyPhase && null !== $candidates[$index]['counterparty'])) {
                                        continue;
                                    }
                                    $claimants = [];
                                    if ('' === $doc && '' !== (string) $docKey) {
                                        $claimants = $numberedClaims[$key][$docKey] ?? [];
                                    }
                                    if (null === $row['counterparty'] && null !== $candidates[$index]['counterparty']) {
                                        $claimants = array_merge($claimants, $partyClaims[$key][$candidates[$index]['counterparty']] ?? []);
                                    }
                                    $reserved = false;
                                    foreach ($claimants as $claimant) {
                                        if (!isset($result[$claimant]) && $this->documentCompatible($rowsById[$claimant], $candidates[$index]) && $this->counterpartyCompatible($rowsById[$claimant], $candidates[$index])) {
                                            $reserved = true;
                                            break;
                                        }
                                    }
                                    if ($reserved) {
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
                    if (count($edges[$row['rowId']] ?? []) < 2) {
                        $edges[$row['rowId']][] = $index;
                    }
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
                    // Exact wildcard skips cannot prove which of otherwise identical
                    // original candidates owns a changed text. Never update an ID
                    // selected merely by the snapshot's iteration order.
                    $originalMatches = 0;
                    foreach ($financial[$this->financialKey($row)] ?? [] as $originalIndex) {
                        $original = $candidates[$originalIndex];
                        if ($this->text($original['description']) === $this->text($candidate['description'])
                            && $this->documentCompatible($row, $original) && $this->counterpartyCompatible($row, $original)) {
                            ++$originalMatches;
                            if ($originalMatches > 1) {
                                break;
                            }
                        }
                    }
                    $result[$rowId] = $originalMatches > 1
                        ? $this->decision('conflict', 'ambiguous_match')
                        : $this->decision('update_description', 'possible_match', $candidate);
                } else {
                    $result[$rowId] = $this->decision('conflict', 'ambiguous_match');
                }
                continue;
            }

            $conflict = null;
            $hasCompatibleFinancial = false;
            foreach ($financial[$this->financialKey($row)] ?? [] as $index) {
                $candidate = $candidates[$index];
                if (!$this->documentCompatible($row, $candidate)) {
                    continue;
                }
                $hasCompatibleFinancial = true;
                if (!$this->counterpartyCompatible($row, $candidate)) {
                    $conflict = 'counterparty_mismatch';
                } elseif ('' !== $this->text($row['description']) && $this->text($row['description']) !== $this->text($candidate['description'])) {
                    $conflict = 'existing_match_exhausted';
                }
            }
            $doc = trim((string) $row['docNumber']);
            foreach ('' === $doc || $hasCompatibleFinancial ? [] : ($documents[$row['date']][$doc] ?? []) as $index) {
                if ($candidates[$index]['amount'] !== $row['amount'] || $candidates[$index]['currency'] !== $row['currency'] || $candidates[$index]['direction'] !== $row['direction']) {
                    $conflict = 'financial_mismatch';
                }
            }
            $result[$rowId] = null === $conflict ? $this->decision('create', 'new_operation') : $this->decision('conflict', $conflict);
        }

        return $result;
    }

    /**
     * @param Row $emptyRow
     * @param list<Row> $meaningfulRows
     * @param list<int> $indexes
     * @param list<Candidate> $candidates
     * @param array<int, bool> $available
     * @param array<string, Decision> $result
     */
    private function emptyDescriptionCompetesWithUpdate(array $emptyRow, array $meaningfulRows, array $indexes, array $candidates, array $available, array $result): bool
    {
        if ([] === $meaningfulRows) {
            return false;
        }
        $compatible = [];
        foreach ($indexes as $index) {
            if (isset($available[$index]) && $this->documentCompatible($emptyRow, $candidates[$index]) && $this->counterpartyCompatible($emptyRow, $candidates[$index])) {
                $compatible[] = $index;
            }
        }
        if (count($compatible) < 2) {
            return false;
        }
        foreach ($meaningfulRows as $row) {
            if (isset($result[$row['rowId']])) {
                continue;
            }
            foreach ($compatible as $index) {
                $candidate = $candidates[$index];
                if ($this->documentCompatible($row, $candidate) && $this->counterpartyCompatible($row, $candidate)
                    && $this->text($row['description']) !== $this->text($candidate['description'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function text(?string $description): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $description ?? '');

        return preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $text) ?? trim($text);
    }

    /** @param Row|Candidate $row */
    private function financialKey(array $row): string
    {
        return json_encode([$row['date'], $row['amount'], $row['direction'], $row['currency']], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param Row $row
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
     * @param Row $row
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
