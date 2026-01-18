<?php

namespace App\Cash\Service\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Enum\CashDirection;
use App\Enum\CounterpartyType;
use App\Repository\CounterpartyRepository;
use App\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ClientBank1CImportService
{
    /** @var array<string, Counterparty> in-memory кэш контрагентов на время одного импорта (ключ: companyId:inn) */
    private array $cpCache = [];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly ImportLogger $importLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountBalanceService $accountBalanceService,
        #[Autowire(service: 'monolog.logger.import.bank1c')]
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    // --- parseHeaderAndDocuments (без изменений) ---
    public function parseHeaderAndDocuments(string $content): array
    {
        $header = [];
        $documents = [];
        $currentDocument = null;

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if ('КонецДокумента' === $line) {
                if (null !== $currentDocument) {
                    $documents[] = $currentDocument;
                    $currentDocument = null;
                }
                continue;
            }
            $parts = explode('=', $line, 2);
            if (2 !== count($parts)) {
                continue;
            }
            [$key, $value] = $parts;
            $key = trim($key);
            $value = trim($value);
            if ('' === $key) {
                continue;
            }
            if ('СекцияДокумент' === $key) {
                if (null !== $currentDocument) {
                    $documents[] = $currentDocument;
                }
                $currentDocument = ['_doc_type' => $value];
                continue;
            }
            if (null === $currentDocument) {
                $header[$key] = $value;
                continue;
            }
            $currentDocument[$key] = $value;
        }
        if (null !== $currentDocument) {
            $documents[] = $currentDocument;
        }

        return ['header' => $header, 'documents' => $documents];
    }

    // --- buildPreview (без изменений) ---
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
            if (null !== $payerInn) {
                $uniqueInns[$payerInn] = true;
            }
            $receiverInn = $this->normalizeInn($this->getStringValue($document['ПолучательИНН'] ?? null));
            if (null !== $receiverInn) {
                $uniqueInns[$receiverInn] = true;
            }
        }

        $existingInns = [];
        if ([] !== $uniqueInns) {
            $counterparties = $this->counterpartyRepository->findBy([
                'company' => $company,
                'inn' => array_keys($uniqueInns),
            ]);
            foreach ($counterparties as $counterparty) {
                $inn = $this->normalizeInn($counterparty->getInn());
                if (null !== $inn) {
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

            $payerName = $this->getStringValue($document['Плательщик'] ?? $document['Плательщик1'] ?? null);
            $payerInn = $this->getStringValue($document['ПлательщикИНН'] ?? null);
            $payerAccount = $this->getStringValue($document['ПлательщикСчет'] ?? $document['ПлательщикРасчСчет'] ?? null);

            $receiverName = $this->getStringValue($document['Получатель'] ?? $document['Получатель1'] ?? null);
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
            if (null !== $counterpartyInn && isset($existingInns[$counterpartyInn])) {
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

    // --- import (с добавлением очистки кэша в начале) ---
    public function import(array $preview, MoneyAccount $account, bool $overwrite, array $context = []): array
    {
        $company = $this->activeCompanyService->getActiveCompany();

        // Очистим кэш контрагентов перед стартом импорта
        $this->cpCache = [];

        $baseLogContext = [
            'user' => $context['user'] ?? null,
            'company' => [
                'id' => $company->getId(),
                'name' => $company->getName(),
            ],
            'file' => $context['file'] ?? null,
            'statement_account' => $context['statement_account'] ?? null,
            'account' => [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'number' => $account->getAccountNumber(),
                'currency' => $account->getCurrency(),
            ],
            'date_start' => $context['date_start'] ?? null,
            'date_end' => $context['date_end'] ?? null,
            'overwrite' => $overwrite,
        ];

        $isPreview = (bool) ($context['preview'] ?? false);
        $importLog = $context['import_log'] ?? null;
        if (!$importLog instanceof ImportLog) {
            $importLog = null;
        }

        $baseLogContext['preview'] = (int) $isPreview;
        $this->logger->info('Bank1C import started', $baseLogContext);

        $created = 0;
        $duplicates = 0;
        $errors = 0;
        $createdMinDate = null;
        $createdMaxDate = null;

        $companyId = $company->getId();
        $accountId = $account->getId();

        foreach ($preview as $rowNo => $row) {
            $currentRow = $rowNo + 1;
            $counterparty = null;

            if (!is_array($row)) {
                ++$errors;
                if (null !== $importLog) {
                    $this->importLogger->incError($importLog);
                }
                $this->logger->warning('[1C Import] row.error', [
                    'company' => $companyId,
                    'rowNo' => $currentRow,
                    'error' => 'row_not_array',
                ]);
                continue;
            }

            $rawData = $this->extractRawData($row);
            $docType = $this->getStringValue($row['docType'] ?? null);
            $docNumber = $this->getStringValue($row['docNumber'] ?? null);
            $purpose = $this->getStringValue($row['purpose'] ?? null);

            $direction = $this->resolveDirection($row);
            if (null === $direction) {
                ++$errors;
                if (null !== $importLog) {
                    $this->importLogger->incError($importLog);
                }
                $this->logger->warning('[1C Import] row.error', [
                    'company' => $companyId,
                    'rowNo' => $currentRow,
                    'error' => 'direction_not_resolved',
                ]);
                continue;
            }

            $occurredAt = $this->resolveOccurredAt($row, $direction);
            if (null === $occurredAt) {
                ++$errors;
                if (null !== $importLog) {
                    $this->importLogger->incError($importLog);
                }
                $this->logger->warning('[1C Import] row.error', [
                    'company' => $companyId,
                    'rowNo' => $currentRow,
                    'error' => 'occurred_at_not_resolved',
                ]);
                continue;
            }

            $amount = $this->resolveAmount($row, $direction);
            if (null === $amount) {
                ++$errors;
                if (null !== $importLog) {
                    $this->importLogger->incError($importLog);
                }
                $this->logger->warning('[1C Import] row.error', [
                    'company' => $companyId,
                    'rowNo' => $currentRow,
                    'error' => 'amount_not_numeric',
                ]);
                continue;
            }

            $externalId = $this->generateExternalId($row, $account);
            $isTransfer = $this->shouldMarkAsTransfer($row, $account);

            $transaction = $this->cashTransactionRepository->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'externalId' => $externalId,
            ]);

            $occurredAtUtc = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
            $amountMinor = (int) str_replace('.', '', $amount);
            $dedupeHash = $this->makeDedupeHash(
                $companyId,
                $accountId,
                $occurredAtUtc,
                $amountMinor,
                $purpose ?? ''
            );

            $shouldFlush = false;
            $isNewTransaction = false;

            if (null === $transaction) {
                if (!$isPreview && $this->cashTransactionRepository->existsByCompanyAndDedupe($companyId, $dedupeHash)) {
                    ++$duplicates;
                    if (null !== $importLog) {
                        $this->importLogger->incSkippedDuplicate($importLog);
                    }
                    $this->logger->info('[1C Import] row.skip_dedupe', [
                        'company' => $companyId,
                        'dedupeHash' => $dedupeHash,
                        'rowNo' => $currentRow,
                        'occurredAt' => $occurredAtUtc->format(\DATE_ATOM),
                        'amountMinor' => $amountMinor,
                    ]);
                    continue;
                }

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
                $transaction->setDedupeHash($dedupeHash);

                if (!$isPreview) {
                    $this->entityManager->persist($transaction);
                    $shouldFlush = true;
                }

                $isNewTransaction = true;
            } else {
                ++$duplicates;

                if (!$overwrite) {
                    if (null !== $importLog) {
                        $this->importLogger->incSkippedDuplicate($importLog);
                    }
                    $this->logger->info('[1C Import] row.skip_externalId', [
                        'company' => $companyId,
                        'externalId' => $externalId,
                        'rowNo' => $currentRow,
                    ]);
                    continue;
                }

                $transaction->setDedupeHash($dedupeHash);

                if (!$isPreview) {
                    $shouldFlush = true;
                }
            }

            if (!$isPreview) {
                $counterparty = $this->getOrCreateCounterpartyFromRow($row, $direction, $company);
            }

            if ($isNewTransaction) {
                $transaction
                    ->setDirection($direction)
                    ->setAmount($amount)
                    ->setCurrency($account->getCurrency())
                    ->setOccurredAt($occurredAt)
                    ->setBookedAt($occurredAt)
                    ->setDescription($purpose)
                    ->setDocType($docType)
                    ->setDocNumber($docNumber)
                    ->setRawData($rawData)
                    ->setIsTransfer($isTransfer)
                    ->setUpdatedAt(new \DateTimeImmutable());

                if ($counterparty instanceof Counterparty) {
                    $transaction->setCounterparty($counterparty);
                } else {
                    $transaction->setCounterparty(null);
                }
            } else {
                $transaction
                    ->setDescription($purpose)
                    ->setRawData($rawData)
                    ->setUpdatedAt(new \DateTimeImmutable());

                if ($counterparty instanceof Counterparty) {
                    $transaction->setCounterparty($counterparty);
                }
            }

            if ($isPreview || !$shouldFlush) {
                continue;
            }

            try {
                $this->entityManager->flush();

                if ($isNewTransaction) {
                    ++$created;
                    if (null !== $importLog) {
                        $this->importLogger->incCreated($importLog);
                    }
                    $this->logger->info('[1C Import] row.created', [
                        'company' => $companyId,
                        'externalId' => $externalId,
                        'rowNo' => $currentRow,
                    ]);

                    if (null === $createdMinDate || $occurredAt < $createdMinDate) {
                        $createdMinDate = $occurredAt;
                    }
                    if (null === $createdMaxDate || $occurredAt > $createdMaxDate) {
                        $createdMaxDate = $occurredAt;
                    }
                } else {
                    $this->logger->info('[1C Import] row.overwrite', [
                        'company' => $companyId,
                        'externalId' => $externalId,
                        'rowNo' => $currentRow,
                    ]);
                }
            } catch (UniqueConstraintViolationException $e) {
                if ($isNewTransaction) {
                    ++$duplicates;
                }
                if (null !== $importLog) {
                    $this->importLogger->incSkippedDuplicate($importLog);
                }
                $this->logger->warning('[1C Import] unique_violation_externalId', [
                    'company' => $companyId,
                    'externalId' => $externalId,
                    'rowNo' => $currentRow,
                    'message' => $e->getMessage(),
                ]);

                $this->entityManager->detach($transaction);
                if ($counterparty instanceof Counterparty) {
                    $this->entityManager->detach($counterparty);
                }
                $this->cpCache = [];

                continue;
            }
        }

        if ($created > 0 && null !== $createdMinDate) {
            $today = new \DateTimeImmutable('today');
            $toDate = $createdMaxDate ?? $createdMinDate;
            if ($createdMinDate <= $today) {
                $toDate = $today;
            }
            $this->accountBalanceService->recalculateDailyRange($company, $account, $createdMinDate, $toDate);
        }

        $summary = [
            'created' => $created,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'skippedDuplicates' => $importLog?->getSkippedDuplicates() ?? 0,
            'minDate' => $createdMinDate,
            'maxDate' => $createdMaxDate,
        ];

        $this->logger->info('Bank1C import finished', array_merge($baseLogContext, [
            'result' => [
                'created' => $created,
                'duplicates' => $duplicates,
                'errors' => $errors,
                'skippedDuplicates' => $importLog?->getSkippedDuplicates() ?? 0,
                'minDate' => $createdMinDate?->format('Y-m-d'),
                'maxDate' => $createdMaxDate?->format('Y-m-d'),
            ],
        ]));

        // На всякий случай очистим кэш после импорта
        $this->cpCache = [];

        return $summary;
    }

    // --- Остальные методы (resolveDirection, resolveOccurredAt, resolveAmount, parseDate) без изменений ---

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

    private function resolveTransferDirection(array $row): CashDirection
    {
        $hasDebit = null !== $this->getStringValue($row['dateDebit'] ?? null);
        $hasCredit = null !== $this->getStringValue($row['dateCredit'] ?? null);

        if ($hasDebit && !$hasCredit) {
            return CashDirection::OUTFLOW;
        }
        if ($hasCredit && !$hasDebit) {
            return CashDirection::INFLOW;
        }

        return CashDirection::OUTFLOW;
    }

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

    private function resolveAmount(array $row, CashDirection $direction): ?string
    {
        $rawAmount = $row['amount'] ?? null;
        if (!is_numeric($rawAmount)) {
            return null;
        }
        $amount = number_format(abs((float) $rawAmount), 2, '.', '');

        return $amount;
    }

    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if (null === $date || '' === $date) {
            return null;
        }
        $formats = ['d.m.Y', 'Y-m-d'];
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $date);
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
     * Получить/создать контрагента из строки превью с учётом направления.
     * Использует кэш, чтобы не плодить дублей до flush().
     */
    private function getOrCreateCounterpartyFromRow(array $row, CashDirection $direction, Company $company): ?Counterparty
    {
        if ('self-transfer' === $this->getStringValue($row['direction'] ?? null)) {
            return null;
        }

        if (CashDirection::OUTFLOW === $direction) {
            $name = $this->getStringValue($row['receiverName'] ?? null);
            $inn = $this->normalizeInn($this->getStringValue($row['receiverInn'] ?? null));
        } else {
            $name = $this->getStringValue($row['payerName'] ?? null);
            $inn = $this->normalizeInn($this->getStringValue($row['payerInn'] ?? null));
        }

        if (null === $inn || null === $name) {
            return null;
        }

        $key = $company->getId().':'.$inn;

        // 1) В кэше?
        if (isset($this->cpCache[$key])) {
            return $this->cpCache[$key];
        }

        // 2) В БД?
        $existing = $this->counterpartyRepository->findOneBy([
            'company' => $company,
            'inn' => $inn,
        ]);
        if ($existing instanceof Counterparty) {
            return $this->cpCache[$key] = $existing;
        }

        // 3) Создаём, кладём в кэш (persist без flush — нормально)
        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            $name,
            $this->determineCounterpartyType($inn),
        );
        $counterparty->setInn($inn);
        $this->entityManager->persist($counterparty);

        return $this->cpCache[$key] = $counterparty;
    }

    private function determineCounterpartyType(string $inn): CounterpartyType
    {
        return match (strlen($inn)) {
            12 => CounterpartyType::INDIVIDUAL_ENTREPRENEUR,
            default => CounterpartyType::LEGAL_ENTITY,
        };
    }

    private function normalizePurposeForDedupe(?string $value): string
    {
        $value = (string) $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    private function makeDedupeHash(string $companyId, string $moneyAccountId, \DateTimeImmutable $occurredAtUtc, int $amountMinor, string $purposeRaw): string
    {
        $payload = $companyId
            .'|'.$moneyAccountId
            .'|'.$occurredAtUtc->format('Y-m-d')
            .'|'.$amountMinor
            .'|'.$this->normalizePurposeForDedupe($purposeRaw);

        return hash('sha256', $payload);
    }

    // --- extractRawData / normalizeRawArray / generateExternalId / shouldMarkAsTransfer / containsInsensitive ---
    // (без изменений)

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
            if (is_scalar($value) || null === $value) {
                $normalized[$key] = null === $value ? null : (string) $value;
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeRawArray($value);
            }
        }

        return $normalized;
    }

    private function normalizeRawArray(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                if (is_scalar($value) || null === $value) {
                    $normalized[$key] = null === $value ? null : (string) $value;
                } elseif (is_array($value)) {
                    $normalized[$key] = $this->normalizeRawArray($value);
                }
            } else {
                $normalized[] = is_scalar($value) || null === $value
                    ? (null === $value ? null : (string) $value)
                    : (is_array($value) ? $this->normalizeRawArray($value) : null);
            }
        }

        return $normalized;
    }

    private function generateExternalId(array $row, MoneyAccount $account): string
    {
        $parts = [
            $this->getStringValue($row['docType'] ?? null) ?? '',
            $this->getStringValue($row['docNumber'] ?? null) ?? '',
            $this->getStringValue($row['docDate'] ?? null) ?? '',
            $this->formatAmountForHash($row['amount'] ?? null),
            $this->normalizeAccount($this->getStringValue($row['payerAccount'] ?? null)) ?? '',
            $this->normalizeAccount($this->getStringValue($row['receiverAccount'] ?? null)) ?? '',
            $this->getStringValue($row['purpose'] ?? null) ?? '',
            $this->normalizeAccount($account->getAccountNumber()) ?? '',
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function shouldMarkAsTransfer(array $row, MoneyAccount $account): bool
    {
        $statementAccount = $this->normalizeAccount($account->getAccountNumber());
        $payerAccount = $this->normalizeAccount($this->getStringValue($row['payerAccount'] ?? null));
        $receiverAccount = $this->normalizeAccount($this->getStringValue($row['receiverAccount'] ?? null));

        if (null !== $statementAccount && $statementAccount === $payerAccount && $statementAccount === $receiverAccount) {
            return true;
        }

        $purpose = $this->getStringValue($row['purpose'] ?? null);
        if (null === $purpose) {
            return false;
        }

        $keywords = ['перевод средств между счетами', 'депозит', 'возврат депозит'];
        foreach ($keywords as $keyword) {
            if ($this->containsInsensitive($purpose, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsInsensitive(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) {
            return false !== mb_stripos($haystack, $needle);
        }

        return false !== stripos($haystack, $needle);
    }

    private function formatAmountForHash(mixed $amount): string
    {
        if (!is_numeric($amount)) {
            return '0.00';
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function getStringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);

            return '' === $value ? null : $value;
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
        if (null === $account) {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', $account);

        return '' === $normalized ? null : $normalized;
    }

    private function normalizeInn(?string $inn): ?string
    {
        if (null === $inn) {
            return null;
        }
        $normalized = preg_replace('/\D+/', '', $inn);

        return '' === $normalized ? null : $normalized;
    }

    private function determineDirection(
        ?string $statementAccount,
        ?string $payerAccount,
        ?string $receiverAccount,
        ?string $dateDebit,
        ?string $dateCredit,
    ): string {
        $isPayerMatch = null !== $statementAccount && null !== $payerAccount && $payerAccount === $statementAccount;
        $isReceiverMatch = null !== $statementAccount && null !== $receiverAccount && $receiverAccount === $statementAccount;

        if ($isPayerMatch && $isReceiverMatch) {
            return 'self-transfer';
        }
        if ($isPayerMatch) {
            return 'outflow';
        }
        if ($isReceiverMatch) {
            return 'inflow';
        }
        if (null !== $dateDebit && null === $dateCredit) {
            return 'outflow';
        }
        if (null !== $dateCredit && null === $dateDebit) {
            return 'inflow';
        }

        return 'outflow';
    }
}
