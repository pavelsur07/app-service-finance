<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\File;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\CounterpartyType;
use App\Company\Facade\CounterpartyFacade;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Ramsey\Uuid\Uuid;

final class CashFileImportService
{
    private const IMPORT_SOURCE = 'file';

    /** @var array<string, Counterparty> */
    private array $counterpartyCache = [];

    /** @var list<CashTransaction> */
    private array $batchTransactions = [];

    /**
     * dedupe_hash → номер документа ('' — без номера) → сколько строк файла с этим ключом
     * закрываются транзакциями, бывшими в БД до импорта (см. planExistingMatches()).
     *
     * @var array<string, array<string, int>>
     */
    private array $matchQuota = [];

    public function __construct(
        private readonly CashFileRowNormalizer $rowNormalizer,
        private readonly CounterpartyNameNormalizer $counterpartyNameNormalizer,
        private readonly CounterpartyFacade $counterpartyFacade,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly ImportLogger $importLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountBalanceService $accountBalanceService,
        private readonly ObjectStorageInterface $objectStorage,
        private readonly TemporaryLocalFile $temporaryLocalFile,
        private readonly LegacyXlsConverter $xlsConverter,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
    ) {
    }

    public function import(CashFileImportJob $job): void
    {
        $company = $job->getCompany();
        $account = $job->getMoneyAccount();
        if ($account->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Счёт импорта принадлежит другой компании.');
        }
        FiatCurrency::fromCode($account->getCurrency());

        $storageKey = $this->resolveStorageKey($job);

        // Файл читается воркером через объектное хранилище: скачиваем во временную
        // локальную копию (readers OpenSpout требуют реальный путь), обрабатываем,
        // TemporaryLocalFile гарантированно удаляет её после.
        $this->temporaryLocalFile->with(
            $storageKey,
            // Устаревший .xls конвертируется в .xlsx: ридера для него у OpenSpout нет,
            // и раньше задача молча умирала в очереди фатальной ошибкой.
            fn (string $filePath) => $this->xlsConverter->withReadablePath(
                $filePath,
                fn (string $readablePath) => $this->readAndPersist($readablePath, $job),
            ),
        );
    }

    private function readAndPersist(string $filePath, CashFileImportJob $job): void
    {
        $company = $job->getCompany();
        $moneyAccount = $job->getMoneyAccount();
        $importLog = $job->getImportLog();
        $mapping = $job->getMapping();
        $companyId = $company->getId();
        $accountId = $moneyAccount->getId();
        $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate($companyId, null, null);
        $systemProject = $this->entityManager->getReference(
            ProjectDirection::class,
            $responsibilityPair->projectDirectionId
        );

        // Сервис живёт в воркере между сообщениями: хвост упавшего импорта не
        // должен попасть в пачку следующего.
        $this->batchTransactions = [];
        $this->counterpartyCache = [];
        $this->matchQuota = [];

        $created = 0;
        $createdMinDate = null;
        $createdMaxDate = null;
        $batchSize = 200;
        $batchCount = 0;

        // Первый проход: сколько строк каждого ключа в файле и сколько из них уже есть в БД.
        $this->matchQuota = $this->planExistingMatches($filePath, $job);

        try {
            try {
                foreach ($this->normalizedRows($filePath, $mapping, $moneyAccount->getCurrency()) as $item) {
                    $rowByHeader = $item['row'];
                    $normalized = $item['normalized'];

                    if (false === $normalized['ok']) {
                        if ($importLog) {
                            $this->importLogger->incError($importLog);
                        }
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
                        continue;
                    }

                    $occurredAtUtc = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
                    $amountMinor = (int) str_replace('.', '', $amount);
                    $dedupeHash = $this->makeDedupeHash(
                        $companyId,
                        $accountId,
                        $occurredAtUtc,
                        $amountMinor,
                        $description ?? ''
                    );

                    if ($this->consumeExistingMatch($dedupeHash, $docNumber)) {
                        if ($importLog) {
                            $this->importLogger->incSkippedDuplicate($importLog);
                        }
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
                    $transaction->setImportSource(self::IMPORT_SOURCE);
                    $transaction->setDocNumber($docNumber);
                    $transaction->setDescription($description);
                    $transaction->setProjectDirection($systemProject);
                    $transaction->setResponsibilityCenterId($responsibilityPair->responsibilityCenterId);
                    $transaction->setBookedAt($occurredAt);
                    $transaction->setRawData([
                        'row' => $rowByHeader,
                        'mapping' => $mapping,
                    ]);
                    $transaction->setUpdatedAt(new \DateTimeImmutable());
                    // Номер документа не идёт в external_id: он повторяется между годами
                    // и счетами, а uniq_cashflow_import валил на нём весь импорт.
                    // Дубли файла ловит isAlreadyImported() по dedupeHash и doc_number.

                    if (null !== $counterpartyName) {
                        $counterparty = $this->getOrCreateCounterparty($companyId, $counterpartyName, $company);
                        $transaction->setCounterparty($counterparty);
                    } else {
                        $transaction->setCounterparty(null);
                    }

                    $this->entityManager->persist($transaction);
                    $this->batchTransactions[] = $transaction;

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
                }
            } finally {
                if ($batchCount > 0) {
                    $this->flushBatch();
                }
            }

            if ($created > 0 && null !== $createdMinDate) {
                $today = new \DateTimeImmutable('today');
                $toDate = $createdMaxDate ?? $createdMinDate;
                if ($createdMinDate <= $today) {
                    $toDate = $today;
                }
                $this->accountBalanceService->recalculateDailyRange($company, $moneyAccount, $createdMinDate, $toDate);
            }
        } finally {
            // На закрытом EM finish() бросил бы и подменил исходное исключение;
            // журнал тогда закрывает CashFileImportHandler после resetManager().
            if ($importLog && $this->entityManager->isOpen()) {
                $this->importLogger->finish($importLog);
            }
        }
    }

    /**
     * Пересчёт остатков по строкам, которые упавший импорт успел зафиксировать:
     * пачки коммитятся по отдельности, а штатный пересчёт стоит после цикла.
     * Повторная загрузка того же файла пропустит эти строки как дубли и диапазон
     * не пересчитает.
     */
    public function recalculateCommittedRows(CashFileImportJob $job): void
    {
        $startedAt = $job->getStartedAt();
        if (null === $startedAt) {
            return;
        }

        $company = $job->getCompany();
        $account = $job->getMoneyAccount();
        $range = $this->cashTransactionRepository->findOccurredRangeByCompanyAccountSourceCreatedSince(
            (string) $company->getId(),
            (string) $account->getId(),
            self::IMPORT_SOURCE,
            $startedAt,
        );
        if (null === $range) {
            return;
        }

        [$from, $to] = $range;
        $today = new \DateTimeImmutable('today');
        if ($from <= $today) {
            $to = $today;
        }
        $this->accountBalanceService->recalculateDailyRange($company, $account, $from, $to);
    }

    /**
     * Ключ файла в объектном хранилище. Кандидаты в порядке приоритета
     * (stored_ext → расширение из имени файла → без расширения) — первый,
     * который существует. Кандидат по имени файла сохраняет совместимость
     * с job'ами, созданными до персиста stored_ext в options.
     */
    private function resolveStorageKey(CashFileImportJob $job): string
    {
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

        $fileExtension = pathinfo($job->getFilename(), \PATHINFO_EXTENSION);
        if ('' !== $fileExtension) {
            $extensions[] = strtolower($fileExtension);
        }

        $candidates = [];
        foreach (array_values(array_unique($extensions)) as $extension) {
            $candidates[] = sprintf('cash-file-imports/%s.%s', $fileHash, $extension);
        }
        $candidates[] = sprintf('cash-file-imports/%s', $fileHash);

        foreach ($candidates as $candidate) {
            if ($this->objectStorage->exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf('Import file not found for hash %s', $fileHash));
    }

    private function openReaderByExtension(string $filePath): ReaderInterface
    {
        $extension = strtolower(pathinfo($filePath, \PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => (function () use ($filePath): CsvReader {
                $options = new CsvOptions();
                $options->FIELD_DELIMITER = $this->detectCsvDelimiter($filePath);

                return new CsvReader($options);
            })(),
            'xlsx' => new XlsxReader(),
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

    /**
     * Строки файла после нормализации. Ридер закрывается, когда обход закончен или прерван.
     *
     * @param array<string, mixed> $mapping
     *
     * @return \Generator<int, array{row: array<string, ?string>, normalized: array{
     *     ok: bool,
     *     errors: list<string>,
     *     occurredAt: ?\DateTimeImmutable,
     *     direction: ?\App\Cash\Enum\Transaction\CashDirection,
     *     amount: ?string,
     *     counterpartyName: ?string,
     *     description: ?string,
     *     currency: string,
     *     docNumber: ?string,
     *     externalId: ?string,
     *     raw: array<string, mixed>
     * }}>
     */
    private function normalizedRows(string $filePath, array $mapping, string $currency): \Generator
    {
        $reader = $this->openReaderByExtension($filePath);

        try {
            $reader->open($filePath);
            foreach ($reader->getSheetIterator() as $sheet) {
                $headerLabels = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if (null === $headerLabels) {
                        $headerLabels = [];
                        foreach ($cells as $index => $value) {
                            $header = $this->normalizeValue($value, true);
                            $headerLabels[$index] = null === $header || '' === $header
                                ? sprintf('Колонка %d', $index + 1)
                                : $header;
                        }

                        continue;
                    }

                    $rowByHeader = [];
                    foreach ($headerLabels as $index => $label) {
                        $rowByHeader[$label] = $this->normalizeValue($cells[$index] ?? null, false);
                    }

                    yield ['row' => $rowByHeader, 'normalized' => $this->rowNormalizer->normalize($rowByHeader, $mapping, $currency)];
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Дубль считается по количеству: каждая транзакция, бывшая в БД до импорта, закрывает не
     * больше одной строки файла. Совпадения распределяются по всему файлу до записи, поэтому
     * результат не зависит ни от порядка строк, ни от нарезки на пачки, ни от уже закоммиченных
     * пачек самого импорта: повтор файла ничего не создаёт, прерванный импорт дописывает
     * недостающее.
     *
     * Номер документа есть не у всех источников (файлы без колонки номера, выписки 1С с номером),
     * поэтому пустой номер совпадает с любым. Для каждого dedupe_hash: сначала строки с номером
     * закрывают транзакции того же номера; оставшиеся строки с номером — транзакции без номера
     * (у них нет другого варианта); строки без номера — всё, что осталось. Такое распределение
     * даёт максимум совпадений. Параллельные задания одного счёта сериализует
     * CashFileImportHandler — иначе снимки обоих были бы одинаковыми.
     *
     * @return array<string, array<string, int>>
     */
    private function planExistingMatches(string $filePath, CashFileImportJob $job): array
    {
        $companyId = (string) $job->getCompany()->getId();
        $accountId = (string) $job->getMoneyAccount()->getId();

        /** @var array<string, array<string, int>> $fileCounts */
        $fileCounts = [];
        foreach ($this->normalizedRows($filePath, $job->getMapping(), $job->getMoneyAccount()->getCurrency()) as $item) {
            $normalized = $item['normalized'];
            if (false === $normalized['ok'] || !$normalized['occurredAt'] || !$normalized['direction'] || !$normalized['amount']) {
                continue;
            }

            $hash = $this->makeDedupeHash(
                $companyId,
                $accountId,
                $normalized['occurredAt']->setTimezone(new \DateTimeZone('UTC')),
                (int) str_replace('.', '', $normalized['amount']),
                $normalized['description'] ?? '',
            );
            $doc = trim((string) $normalized['docNumber']);
            $fileCounts[$hash][$doc] = ($fileCounts[$hash][$doc] ?? 0) + 1;
        }

        $quota = [];
        foreach ($fileCounts as $hash => $fileDocs) {
            $quota[$hash] = $this->allocateExistingMatches(
                $fileDocs,
                $this->cashTransactionRepository->countActiveByCompanyDedupeGroupedByDocNumber($companyId, $hash),
            );
        }

        return $quota;
    }

    /**
     * @param array<string, int> $fileDocs номер документа → строк в файле
     * @param array<string, int> $existing номер документа → транзакций в БД
     *
     * @return array<string, int> номер документа → сколько строк файла уже импортированы
     */
    private function allocateExistingMatches(array $fileDocs, array $existing): array
    {
        $matched = [];
        $unmatchedNumbered = [];
        foreach ($fileDocs as $doc => $count) {
            $doc = (string) $doc;
            if ('' === $doc) {
                continue;
            }
            $exact = min($count, $existing[$doc] ?? 0);
            $matched[$doc] = $exact;
            $existing[$doc] = ($existing[$doc] ?? 0) - $exact;
            $unmatchedNumbered[$doc] = $count - $exact;
        }

        ksort($unmatchedNumbered, \SORT_STRING);
        $blank = $existing[''] ?? 0;
        foreach ($unmatchedNumbered as $doc => $count) {
            $viaBlank = min($count, $blank);
            $matched[(string) $doc] += $viaBlank;
            $blank -= $viaBlank;
        }
        $existing[''] = $blank;

        if (isset($fileDocs[''])) {
            $matched[''] = min($fileDocs[''], array_sum($existing));
        }

        return $matched;
    }

    private function consumeExistingMatch(string $dedupeHash, ?string $docNumber): bool
    {
        $doc = trim((string) $docNumber);
        if (($this->matchQuota[$dedupeHash][$doc] ?? 0) > 0) {
            --$this->matchQuota[$dedupeHash][$doc];

            return true;
        }

        return false;
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
        \DateTimeImmutable $occurredAtUtc,
        int $amountMinor,
        string $purposeRaw,
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

        // Матчинг идёт по нормализованному ключу, а не по строке из файла: иначе
        // «ООО "Ромашка"» и «"Ромашка" ООО» из разных выписок создают двух
        // контрагентов. ОПФ входит в ключ, чтобы ООО и АО с одним названием
        // остались разными юрлицами.
        $normalizedName = $this->counterpartyNameNormalizer->normalize($trimmedName);

        $cacheKey = $companyId.':'.$normalizedName->core.':'.($normalizedName->legalFormHint ?? '');
        if (isset($this->counterpartyCache[$cacheKey])) {
            return $this->counterpartyCache[$cacheKey];
        }

        // Ищет и создаёт (persist без flush) Company — импорт только кэширует.
        return $this->counterpartyCache[$cacheKey] = $this->counterpartyFacade->findOrCreateByNormalizedName(
            $company,
            $normalizedName,
            CounterpartyType::LEGAL_ENTITY,
        );
    }

    /**
     * Отсоединяет только сущности пачки. clear() в ORM 3 не принимает класс и
     * очищает весь UnitOfWork: компания, счёт, журнал и job отсоединялись, и
     * импорт падал после первой пачки или на закрытии журнала.
     */
    private function flushBatch(): void
    {
        $this->entityManager->flush();
        foreach ($this->batchTransactions as $transaction) {
            $this->entityManager->detach($transaction);
        }
        foreach ($this->counterpartyCache as $counterparty) {
            $this->entityManager->detach($counterparty);
        }
        $this->batchTransactions = [];
        $this->counterpartyCache = [];
    }

    private function detectCsvDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return ',';
        }

        $delimiters = [';', ',', "\t"];
        $counts = array_fill_keys($delimiters, 0);
        $linesRead = 0;

        try {
            while (!feof($handle) && $linesRead < 5) {
                $line = fgets($handle);
                if (false === $line) {
                    break;
                }

                $trimmed = trim($line);
                if ('' === $trimmed) {
                    continue;
                }

                foreach ($delimiters as $delimiter) {
                    $counts[$delimiter] += substr_count($line, $delimiter);
                }

                ++$linesRead;
            }
        } finally {
            fclose($handle);
        }

        $bestDelimiter = ',';
        $bestCount = 0;
        foreach ($counts as $delimiter => $count) {
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }
}
