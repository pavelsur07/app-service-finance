<?php

namespace App\Service\Import;

use App\Entity\MoneyAccount;
use App\Repository\CounterpartyRepository;
use App\Service\ActiveCompanyService;

class ClientBank1CImportService
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CounterpartyRepository $counterpartyRepository,
    ) {
    }

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
     * @param array<int, array<string, mixed>> $documents Parsed document data.
     * @param string $statementAccount Identifier of the account from the statement.
     *
     * @return array<int, array{
     *     docType: ?string,
     *     docNumber: ?string,
     *     docDate: ?string,
     *     amount: float,
     *     payerName: ?string,
     *     payerInn: ?string,
     *     payerAccount: ?string,
     *     receiverName: ?string,
     *     receiverInn: ?string,
     *     receiverAccount: ?string,
     *     dateDebit: ?string,
     *     dateCredit: ?string,
     *     purpose: ?string,
     *     direction: string,
     *     counterpartyStatus: string,
     * }>
     */
    public function buildPreview(array $documents, string $statementAccount): array
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $normalizedStatementAccount = $this->normalizeAccount($statementAccount);

        $uniqueInns = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $payerInn = $this->normalizeInn($this->getStringValue($document['ПлательщикИНН'] ?? null));
            if ($payerInn !== null) {
                $uniqueInns[$payerInn] = true;
            }

            $receiverInn = $this->normalizeInn($this->getStringValue($document['ПолучательИНН'] ?? null));
            if ($receiverInn !== null) {
                $uniqueInns[$receiverInn] = true;
            }
        }

        $existingInns = [];
        if ($uniqueInns !== []) {
            $counterparties = $this->counterpartyRepository->findBy([
                'company' => $company,
                'inn' => array_keys($uniqueInns),
            ]);

            foreach ($counterparties as $counterparty) {
                $inn = $this->normalizeInn($counterparty->getInn());
                if ($inn !== null) {
                    $existingInns[$inn] = true;
                }
            }
        }

        $previewRows = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $docType = $this->getStringValue($document['_doc_type'] ?? null);
            $docNumber = $this->getStringValue($document['Номер'] ?? null);
            $docDate = $this->getStringValue($document['Дата'] ?? null);
            $amount = $this->parseAmount($document['Сумма'] ?? null);

            $payerName = $this->getStringValue($document['Плательщик'] ?? null);
            $payerInn = $this->getStringValue($document['ПлательщикИНН'] ?? null);
            $payerAccount = $this->getStringValue($document['ПлательщикСчет'] ?? $document['ПлательщикРасчСчет'] ?? null);

            $receiverName = $this->getStringValue($document['Получатель'] ?? null);
            $receiverInn = $this->getStringValue($document['ПолучательИНН'] ?? null);
            $receiverAccount = $this->getStringValue($document['ПолучательСчет'] ?? $document['ПолучательРасчСчет'] ?? null);

            $dateDebit = $this->getStringValue($document['ДатаСписано'] ?? null);
            $dateCredit = $this->getStringValue($document['ДатаПоступило'] ?? null);
            $purpose = $this->getStringValue($document['НазначениеПлатежа'] ?? null);

            $payerAccountNormalized = $this->normalizeAccount($payerAccount);
            $receiverAccountNormalized = $this->normalizeAccount($receiverAccount);

            $direction = $this->determineDirection(
                $normalizedStatementAccount,
                $payerAccountNormalized,
                $receiverAccountNormalized,
                $dateDebit,
                $dateCredit,
            );

            $counterpartyInn = match ($direction) {
                'outflow' => $this->normalizeInn($receiverInn),
                'inflow' => $this->normalizeInn($payerInn),
                'self-transfer' => null,
                default => $this->normalizeInn($receiverInn) ?? $this->normalizeInn($payerInn),
            };

            $counterpartyStatus = 'WILL_CREATE';
            if ($counterpartyInn !== null && isset($existingInns[$counterpartyInn])) {
                $counterpartyStatus = 'FOUND';
            }

            $previewRows[] = [
                'docType' => $docType,
                'docNumber' => $docNumber,
                'docDate' => $docDate,
                'amount' => $amount,
                'payerName' => $payerName,
                'payerInn' => $payerInn,
                'payerAccount' => $payerAccount,
                'receiverName' => $receiverName,
                'receiverInn' => $receiverInn,
                'receiverAccount' => $receiverAccount,
                'dateDebit' => $dateDebit,
                'dateCredit' => $dateCredit,
                'purpose' => $purpose,
                'direction' => $direction,
                'counterpartyStatus' => $counterpartyStatus,
            ];
        }

        return $previewRows;
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

    private function getStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function parseAmount(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace(' ', '', $value);
            $normalized = str_replace(',', '.', $normalized);

            return (float) $normalized;
        }

        return 0.0;
    }

    private function normalizeAccount(?string $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $account);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeInn(?string $inn): ?string
    {
        if ($inn === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $inn);

        return $normalized === '' ? null : $normalized;
    }

    private function determineDirection(
        ?string $statementAccount,
        ?string $payerAccount,
        ?string $receiverAccount,
        ?string $dateDebit,
        ?string $dateCredit,
    ): string {
        $isPayerMatch = $statementAccount !== null && $payerAccount !== null && $payerAccount === $statementAccount;
        $isReceiverMatch = $statementAccount !== null && $receiverAccount !== null && $receiverAccount === $statementAccount;

        if ($isPayerMatch && $isReceiverMatch) {
            return 'self-transfer';
        }

        if ($isPayerMatch) {
            return 'outflow';
        }

        if ($isReceiverMatch) {
            return 'inflow';
        }

        if ($dateDebit !== null && $dateCredit === null) {
            return 'outflow';
        }

        if ($dateCredit !== null && $dateDebit === null) {
            return 'inflow';
        }

        return 'outflow';
    }
}
