<?php

namespace App\Service\Import;

use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Enum\CounterpartyType;
use App\Repository\CashTransactionRepository;
use App\Repository\CounterpartyRepository;
use App\Service\AccountBalanceService;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class ClientBank1CImportService
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountBalanceService $accountBalanceService,
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
        $company = $this->activeCompanyService->getActiveCompany();

        $created = 0;
        $duplicates = 0;
        $errors = 0;
        $minDate = null;
        $maxDate = null;
        $processedTransactions = [];

        foreach ($preview as $row) {
            if (!is_array($row)) {
                ++$errors;

                continue;
            }

            $rawData = $this->extractRawData($row);
            $docType = $this->getStringValue($row['docType'] ?? null);
            $docNumber = $this->getStringValue($row['docNumber'] ?? null);
            $purpose = $this->getStringValue($row['purpose'] ?? null);

            $direction = $this->resolveDirection($row);
            if ($direction === null) {
                ++$errors;

                continue;
            }

            $occurredAt = $this->resolveOccurredAt($row, $direction);
            if ($occurredAt === null) {
                ++$errors;

                continue;
            }

            $amount = $this->resolveAmount($row, $direction);
            if ($amount === null) {
                ++$errors;

                continue;
            }

            $externalId = $this->generateExternalId($row, $account, $rawData);

            $transaction = $this->cashTransactionRepository->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'externalId' => $externalId,
            ]);

            if ($transaction !== null) {
                ++$duplicates;

                if (!$overwrite) {
                    continue;
                }
            } else {
                $transaction = new CashTransaction(
                    Uuid::uuid4()->toString(),
                    $company,
                    $account,
                    $direction,
                    $amount,
                    $account->getCurrency(),
                    $occurredAt,
                );
                $transaction->setExternalId($externalId);
                $this->entityManager->persist($transaction);
                ++$created;
            }

            $transaction
                ->setDirection($direction)
                ->setAmount($amount)
                ->setCurrency($account->getCurrency())
                ->setOccurredAt($occurredAt)
                ->setBookedAt($occurredAt)
                ->setExternalId($externalId)
                ->setDescription($purpose)
                ->setDocType($docType)
                ->setDocNumber($docNumber)
                ->setRawData($rawData)
                ->setUpdatedAt(new \DateTimeImmutable());

            $counterparty = $this->resolveCounterparty($row, $direction, $company);
            if ($counterparty instanceof Counterparty) {
                $transaction->setCounterparty($counterparty);
            } else {
                $transaction->setCounterparty(null);
            }

            $processedTransactions[] = $transaction;

            if ($minDate === null || $occurredAt < $minDate) {
                $minDate = $occurredAt;
            }

            if ($maxDate === null || $occurredAt > $maxDate) {
                $maxDate = $occurredAt;
            }
        }

        if (!empty($processedTransactions)) {
            $this->entityManager->flush();
        }

        if ($minDate !== null && $maxDate !== null) {
            $this->accountBalanceService->recalculateDailyRange($company, $account, $minDate, $maxDate);
        }

        return [
            'created' => $created,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'minDate' => $minDate,
            'maxDate' => $maxDate,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveDirection(array $row): ?CashDirection
    {
        $direction = $this->getStringValue($row['direction'] ?? null);

        return match ($direction) {
            'inflow' => CashDirection::INFLOW,
            'outflow' => CashDirection::OUTFLOW,
            'self-transfer' => $this->resolveTransferDirection($row),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveTransferDirection(array $row): CashDirection
    {
        $hasDebit = $this->getStringValue($row['dateDebit'] ?? null) !== null;
        $hasCredit = $this->getStringValue($row['dateCredit'] ?? null) !== null;

        if ($hasDebit && !$hasCredit) {
            return CashDirection::OUTFLOW;
        }

        if ($hasCredit && !$hasDebit) {
            return CashDirection::INFLOW;
        }

        return CashDirection::OUTFLOW;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveOccurredAt(array $row, CashDirection $direction): ?\DateTimeImmutable
    {
        $docDate = $this->parseDate($this->getStringValue($row['docDate'] ?? null));
        $dateDebit = $this->parseDate($this->getStringValue($row['dateDebit'] ?? null));
        $dateCredit = $this->parseDate($this->getStringValue($row['dateCredit'] ?? null));

        return match ($direction) {
            CashDirection::OUTFLOW => $dateDebit ?? $docDate,
            CashDirection::INFLOW => $dateCredit ?? $docDate,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveAmount(array $row, CashDirection $direction): ?string
    {
        $rawAmount = $row['amount'] ?? null;
        if (!is_numeric($rawAmount)) {
            return null;
        }

        $amount = number_format(abs((float) $rawAmount), 2, '.', '');

        if ($direction === CashDirection::OUTFLOW) {
            $amount = '-' . $amount;
        }

        return $amount;
    }

    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        $formats = ['d.m.Y', 'Y-m-d'];
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $date);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveCounterparty(array $row, CashDirection $direction, Company $company): ?Counterparty
    {
        if ($this->getStringValue($row['direction'] ?? null) === 'self-transfer') {
            return null;
        }

        $name = null;
        $inn = null;

        if ($direction === CashDirection::OUTFLOW) {
            $name = $this->getStringValue($row['receiverName'] ?? null);
            $inn = $this->normalizeInn($this->getStringValue($row['receiverInn'] ?? null));
        } else {
            $name = $this->getStringValue($row['payerName'] ?? null);
            $inn = $this->normalizeInn($this->getStringValue($row['payerInn'] ?? null));
        }

        if ($inn === null || $name === null) {
            return null;
        }

        $counterparty = $this->counterpartyRepository->findOneBy([
            'company' => $company,
            'inn' => $inn,
        ]);

        if ($counterparty instanceof Counterparty) {
            return $counterparty;
        }

        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            $name,
            $this->determineCounterpartyType($inn),
        );
        $counterparty->setInn($inn);
        $this->entityManager->persist($counterparty);

        return $counterparty;
    }

    private function determineCounterpartyType(string $inn): CounterpartyType
    {
        return match (strlen($inn)) {
            12 => CounterpartyType::INDIVIDUAL_ENTREPRENEUR,
            default => CounterpartyType::LEGAL_ENTITY,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function extractRawData(array $row): array
    {
        $raw = $row['raw'] ?? $row['rawData'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $normalized = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value === null ? null : (string) $value;
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeRawArray($value);
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function normalizeRawArray(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                if (is_scalar($value) || $value === null) {
                    $normalized[$key] = $value === null ? null : (string) $value;
                } elseif (is_array($value)) {
                    $normalized[$key] = $this->normalizeRawArray($value);
                }
            } else {
                $normalized[] = is_scalar($value) || $value === null
                    ? ($value === null ? null : (string) $value)
                    : (is_array($value) ? $this->normalizeRawArray($value) : null);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $rawData
     */
    private function generateExternalId(array $row, MoneyAccount $account, array $rawData): string
    {
        $payload = [
            'source' => 'client_bank_1c',
            'account' => $account->getId(),
            'docType' => $this->getStringValue($row['docType'] ?? null),
            'docNumber' => $this->getStringValue($row['docNumber'] ?? null),
            'docDate' => $this->getStringValue($row['docDate'] ?? null),
            'amount' => $row['amount'] ?? null,
            'dateDebit' => $this->getStringValue($row['dateDebit'] ?? null),
            'dateCredit' => $this->getStringValue($row['dateCredit'] ?? null),
            'direction' => $this->getStringValue($row['direction'] ?? null),
            'purpose' => $this->getStringValue($row['purpose'] ?? null),
            'raw' => $rawData,
        ];

        $this->sortRecursive($payload);

        return 'client-bank-1c:' . sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<mixed> $data
     */
    private function sortRecursive(array &$data): void
    {
        if (array_is_list($data)) {
            foreach ($data as &$value) {
                if (is_array($value)) {
                    $this->sortRecursive($value);
                }
            }

            return;
        }

        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
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
