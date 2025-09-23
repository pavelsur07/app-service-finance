<?php

namespace App\Service\Import;

use App\Entity\MoneyAccount;

class ClientBank1CImportService
{
    /**
     * Parse content of a Client Bank 1C export and return its header and documents sections.
     *
     * @param string $content Raw file content.
     *
     * @return array{
     *     header: array<mixed>,
     *     documents: array<mixed>
     * } Parsed header and documents data.
     */
    public function parseHeaderAndDocuments(string $content): array
    {
        $header = [];
        $documents = [];
        $currentDocument = null;

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($line === 'КонецДокумента') {
                if ($currentDocument !== null) {
                    $documents[] = $currentDocument;
                    $currentDocument = null;
                }

                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if ($key === 'СекцияДокумент') {
                if ($currentDocument !== null) {
                    $documents[] = $currentDocument;
                }

                $currentDocument = [
                    '_doc_type' => $value,
                ];

                continue;
            }

            if ($currentDocument === null) {
                $header[$key] = $value;

                continue;
            }

            $currentDocument[$key] = $value;
        }

        if ($currentDocument !== null) {
            $documents[] = $currentDocument;
        }

        return [
            'header' => $header,
            'documents' => $documents,
        ];
    }

    /**
     * Build preview data for the provided documents and statement account identifier.
     *
     * @param array<mixed> $documents Parsed document data.
     * @param string $statementAccount Identifier of the account from the statement.
     *
     * @return array<int, string> Preview lines ready for display.
     */
    public function buildPreview(array $documents, string $statementAccount): array
    {
        return [];
    }

    /**
     * Import preview data into the provided money account.
     *
     * @param array<int, array<string, mixed>> $preview Prepared preview rows ready for import.
     * @param MoneyAccount $account Target account for the import.
     * @param bool $overwrite Whether to overwrite existing data during import.
     *
     * @return array{
     *     created: int,
     *     duplicates: int,
     *     errors: int,
     *     minDate: ?\DateTimeInterface,
     *     maxDate: ?\DateTimeInterface
     * } Import summary statistics.
     */
    public function import(array $preview, MoneyAccount $account, bool $overwrite): array
    {
        return [
            'created' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'minDate' => null,
            'maxDate' => null,
        ];
    }
}
