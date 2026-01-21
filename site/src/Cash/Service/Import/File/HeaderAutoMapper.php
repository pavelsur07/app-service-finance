<?php

namespace App\Cash\Service\Import\File;

final class HeaderAutoMapper
{
    /**
     * @param array<int, string|null> $headers
     *
     * @return array<string, string>
     */
    public function suggest(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            if (null === $header || '' === trim($header)) {
                continue;
            }

            $label = $header;
            $normalizedHeaders[] = [
                'label' => $label,
                'normalized' => $this->normalize($label),
            ];
        }

        $mapping = [];
        $used = [];
        foreach ($this->getSynonyms() as $field => $synonyms) {
            foreach ($normalizedHeaders as $header) {
                if (isset($used[$header['label']])) {
                    continue;
                }

                foreach ($synonyms as $synonym) {
                    if ($this->matches($header['normalized'], $synonym)) {
                        $mapping[$field] = $header['label'];
                        $used[$header['label']] = true;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getSynonyms(): array
    {
        return [
            'date' => ['дата', 'date', 'operation_date', 'проведено'],
            'amount' => ['сумма', 'amount', 'итого'],
            'inflow' => ['приход', 'in', 'income', 'debit'],
            'outflow' => ['расход', 'out', 'expense', 'credit'],
            'counterparty' => ['контрагент', 'плательщик', 'получатель', 'counterparty'],
            'description' => ['назначение', 'описание', 'комментарий', 'purpose'],
            'currency' => ['валюта', 'currency'],
            'doc_number' => ['номер', 'документ', 'doc', 'document_no'],
        ];
    }

    private function matches(string $normalizedHeader, string $synonym): bool
    {
        $normalizedSynonym = $this->normalize($synonym);

        return '' !== $normalizedSynonym && str_contains($normalizedHeader, $normalizedSynonym);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        return $value;
    }
}
