<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ImportLogger;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Enum\CounterpartyType;
use App\Repository\CounterpartyRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLS\Reader as XlsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CashFileImportService
{
    /** @var array<string, Counterparty> */
    private array $counterpartyCache = [];

    public function __construct(
        private readonly CashFileRowNormalizer $rowNormalizer,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly ImportLogger $importLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountBalanceService $accountBalanceService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function import(CashFileImportJob $job): void
    {
        $company = $job->getCompany();
        $moneyAccount = $job->getMoneyAccount();
        $importLog = $job->getImportLog();
        $mapping = $job->getMapping();
        $companyId = $company->getId();
        $accountId = $moneyAccount->getId();

        $filePath = $this->resolveFilePath($job);

        $created = 0;
        $createdMinDate = null;
        $createdMaxDate = null;
        $batchSize = 200;
        $batchCount = 0;

        $reader = $this->openReaderByExtension($filePath);

        try {
            try {
                $reader->open($filePath);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $rowIndex = 0;
                    $headerLabels = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $cells = $row->toArray();
                        if (0 === $rowIndex) {
                            $headers = array_map(
                                fn ($value) => $this->normalizeValue($value, true),
                                $cells
                            );
                            foreach ($headers as $index => $header) {
                                if (null === $header || '' === $header) {
                                    $headerLabels[$index] = sprintf('Колонка %d', $index + 1);
                                } else {
                                    $headerLabels[$index] = $header;
                                }
                            }
                            ++$rowIndex;
                            continue;
                        }

                        $rowByHeader = [];
                        foreach ($headerLabels as $index => $label) {
                            $rowByHeader[$label] = $this->normalizeValue($cells[$index] ?? null, false);
                        }

                        $normalized = $this->rowNormalizer->normalize(
                            $rowByHeader,
                            $mapping,
                            $moneyAccount->getCurrency()
                        );

                        if (false === $normalized['ok']) {
                            if ($importLog) {
                                $this->importLogger->incError($importLog);
                            }
                            ++$rowIndex;
                            continue;
                        }

                        $occurredAt = $normalized['occurredAt'];
                        $direction = $normalized['direction'];
                        $amount = $normalized['amount'];
                        $currency = $normalized['currency'];
                        $description = $normalized['description'];
                        $docNumber = $normalized['docNumber'];
                        $counterpartyName = $normalized['counterpartyName'];

                        if (!$occurredAt || !$direction || !$amount) {
                            if ($importLog) {
                                $this->importLogger->incError($importLog);
                            }
                            ++$rowIndex;
                            continue;
                        }

                        $occurredAtUtc = $occurredAt->setTimezone(new DateTimeZone('UTC'));
                        $amountMinor = (int) str_replace('.', '', $amount);
                        $dedupeHash = $this->makeDedupeHash(
                            $companyId,
                            $accountId,
                            $occurredAtUtc,
                            $amountMinor,
                            $description ?? ''
                        );

                        if ($this->cashTransactionRepository->existsByCompanyAndDedupe($companyId, $dedupeHash)) {
                            if ($importLog) {
                                $this->importLogger->incSkippedDuplicate($importLog);
                            }
                            ++$rowIndex;
                            continue;
                        }

                        $transaction = new CashTransaction(
                            Uuid::uuid4()->toString(),
                            $company,
                            $moneyAccount,
                            $direction,
                            $amount,
                            $currency,
                            $occurredAt,
                        );
                        $transaction->setDedupeHash($dedupeHash);
                        $transaction->setImportSource('file');
                        $transaction->setDocNumber($docNumber);
                        $transaction->setDescription($description);
                        $transaction->setBookedAt($occurredAt);
                        $transaction->setRawData([
                            'row' => $rowByHeader,
                            'mapping' => $mapping,
                        ]);
                        $transaction->setUpdatedAt(new DateTimeImmutable());

                        if (is_string($docNumber)) {
                            $trimmedDocNumber = trim($docNumber);
                            if ('' !== $trimmedDocNumber) {
                                $transaction->setExternalId($trimmedDocNumber);
                            }
                        }

                        if (null !== $counterpartyName) {
                            $counterparty = $this->getOrCreateCounterparty($companyId, $counterpartyName, $company);
                            $transaction->setCounterparty($counterparty);
                        } else {
                            $transaction->setCounterparty(null);
                        }

                        $this->entityManager->persist($transaction);

                        ++$created;
                        if ($importLog) {
                            $this->importLogger->incCreated($importLog);
                        }

                        if (null === $createdMinDate || $occurredAt < $createdMinDate) {
                            $createdMinDate = $occurredAt;
                        }
                        if (null === $createdMaxDate || $occurredAt > $createdMaxDate) {
                            $createdMaxDate = $occurredAt;
                        }

                        ++$batchCount;
                        if ($batchCount >= $batchSize) {
                            $this->flushBatch();
                            $batchCount = 0;
                        }

                        ++$rowIndex;
                    }
                    break;
                }
            } finally {
                $reader->close();
                if ($batchCount > 0) {
                    $this->flushBatch();
                }
            }

            if ($created > 0 && null !== $createdMinDate) {
                $today = new DateTimeImmutable('today');
                $toDate = $createdMaxDate ?? $createdMinDate;
                if ($createdMinDate <= $today) {
                    $toDate = $today;
                }
                $this->accountBalanceService->recalculateDailyRange($company, $moneyAccount, $createdMinDate, $toDate);
            }
        } finally {
            if ($importLog) {
                $this->importLogger->finish($importLog);
            }
        }
    }

    private function resolveFilePath(CashFileImportJob $job): string
    {
        $storageDir = sprintf('%s/var/storage/cash-file-imports', $this->projectDir);
        $fileHash = $job->getFileHash();

        $extensions = [];
        $options = $job->getOptions();
        $storedExtension = $options['stored_ext'] ?? $options['extension'] ?? null;
        if (is_string($storedExtension)) {
            $storedExtension = strtolower(trim($storedExtension));
            if ('' !== $storedExtension) {
                $extensions[] = $storedExtension;
            }
        }

        $fileExtension = pathinfo($job->getFilename(), PATHINFO_EXTENSION);
        if ('' !== $fileExtension) {
            $extensions[] = strtolower($fileExtension);
        }

        $extensions = array_values(array_unique($extensions));

        foreach ($extensions as $extension) {
            $candidate = sprintf('%s/%s.%s', $storageDir, $fileHash, $extension);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $noExtensionPath = sprintf('%s/%s', $storageDir, $fileHash);
        if (is_file($noExtensionPath)) {
            return $noExtensionPath;
        }

        $fallbackMatches = glob(sprintf('%s/%s.*', $storageDir, $fileHash)) ?: [];
        if ([] !== $fallbackMatches) {
            return $fallbackMatches[0];
        }

        throw new \RuntimeException(sprintf('Import file not found for hash %s', $fileHash));
    }

    private function openReaderByExtension(string $filePath): ReaderInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => new CsvReader(),
            'xlsx' => new XlsxReader(),
            'xls' => new XlsReader(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported file extension: %s', $extension)),
        };
    }

    private function normalizeValue(mixed $value, bool $trim): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $stringValue = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $stringValue = $value ? '1' : '0';
        } elseif (is_scalar($value) || $value instanceof \Stringable) {
            $stringValue = (string) $value;
        } else {
            $stringValue = (string) $value;
        }

        if ('' === trim($stringValue)) {
            return null;
        }

        return $trim ? trim($stringValue) : $stringValue;
    }

    private function normalizePurposeForDedupe(?string $value): string
    {
        $value = (string) $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    private function makeDedupeHash(
        string $companyId,
        string $moneyAccountId,
        DateTimeImmutable $occurredAtUtc,
        int $amountMinor,
        string $purposeRaw
    ): string {
        $payload = $companyId
            .'|'.$moneyAccountId
            .'|'.$occurredAtUtc->format('Y-m-d')
            .'|'.$amountMinor
            .'|'.$this->normalizePurposeForDedupe($purposeRaw);

        return hash('sha256', $payload);
    }

    private function getOrCreateCounterparty(string $companyId, string $name, Company $company): Counterparty
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new \RuntimeException('Counterparty name is empty.');
        }

        $cacheKey = $companyId.':'.mb_strtolower($trimmedName);
        if (isset($this->counterpartyCache[$cacheKey])) {
            return $this->counterpartyCache[$cacheKey];
        }

        $existing = $this->counterpartyRepository->findOneBy([
            'company' => $company,
            'name' => $trimmedName,
        ]);
        if ($existing instanceof Counterparty) {
            return $this->counterpartyCache[$cacheKey] = $existing;
        }

        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            $trimmedName,
            CounterpartyType::LEGAL_ENTITY
        );
        $this->entityManager->persist($counterparty);

        return $this->counterpartyCache[$cacheKey] = $counterparty;
    }

    private function flushBatch(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear(CashTransaction::class);
        $this->entityManager->clear(Counterparty::class);
        $this->counterpartyCache = [];
    }
}
