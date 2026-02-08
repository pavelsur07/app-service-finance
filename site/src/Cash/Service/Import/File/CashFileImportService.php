<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
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
        $this->logToConsole("=== НАЧАЛО ИМПОРТА ЗАДАЧИ {$job->getId()} ===");

        $company = $job->getCompany();
        $moneyAccount = $job->getMoneyAccount();
        $importLog = $job->getImportLog();
        $mapping = $job->getMapping();
        $companyId = $company->getId();
        $accountId = $moneyAccount->getId();

        $filePath = $this->resolveFilePath($job);
        $this->logToConsole("Файл: " . $filePath);

        $created = 0;
        $createdMinDate = null;
        $createdMaxDate = null;
        $batchSize = 200;
        $batchCount = 0;
        $rowsRead = 0;

        $reader = $this->openReaderByExtension($filePath);

        try {
            $reader->open($filePath);

            foreach ($reader->getSheetIterator() as $sheet) {
                // В Excel берем только первый лист, где есть данные
                if ($created > 0) break;

                $this->logToConsole("Лист: " . $sheet->getName());

                $headerLabels = [];
                $headersFound = false;
                $linesCheckedForHeader = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowsRead++;
                    $cells = $row->toArray();

                    // Очистка пустых строк
                    $isEmptyRow = true;
                    foreach ($cells as $cell) {
                        if ($cell instanceof \DateTimeInterface) {
                            $isEmptyRow = false; break;
                        }
                        if (trim((string)$cell) !== '') {
                            $isEmptyRow = false; break;
                        }
                    }
                    if ($isEmptyRow) continue;

                    // --- ЛОГИКА ПОИСКА ЗАГОЛОВКА ---
                    if (!$headersFound) {
                        $linesCheckedForHeader++;

                        // Пытаемся понять, заголовок ли это.
                        // Критерий: В строке должно быть минимум 2 заполненные ячейки
                        $nonEmptyCells = 0;
                        foreach ($cells as $c) {
                            if (trim((string)$c) !== '') $nonEmptyCells++;
                        }

                        if ($nonEmptyCells < 2) {
                            // Если проверили уже 20 строк и не нашли заголовка - беда
                            if ($linesCheckedForHeader > 20) {
                                $this->logToConsole("ОШИБКА: Просмотрено 20 строк, заголовок таблицы не найден.");
                                break;
                            }
                            continue; // Ищем дальше
                        }

                        // Нашли строку-кандидат на заголовок
                        $this->logToConsole("ЗАГОЛОВОК НАЙДЕН (строка $rowsRead): " . json_encode($cells, JSON_UNESCAPED_UNICODE));

                        $headers = array_map(fn ($v) => $this->normalizeValue($v, true), $cells);
                        foreach ($headers as $index => $header) {
                            $headerLabels[$index] = ($header === '' || $header === null)
                                ? sprintf('Col_%d', $index)
                                : $header;
                        }
                        $headersFound = true;
                        continue;
                    }

                    // --- ЛОГИКА ИМПОРТА ДАННЫХ ---
                    $rowByHeader = [];
                    // Сопоставляем данные с найденными заголовками
                    foreach ($headerLabels as $index => $label) {
                        $val = $cells[$index] ?? null;
                        $rowByHeader[$label] = $this->normalizeValue($val, false);
                    }

                    $normalized = $this->rowNormalizer->normalize(
                        $rowByHeader,
                        $mapping,
                        $moneyAccount->getCurrency()
                    );

                    if (false === $normalized['ok']) {
                        if ($importLog) $this->importLogger->incError($importLog);
                        // Логируем только первые 3 ошибки
                        if ($rowsRead < 25) $this->logToConsole("Ошибка маппинга строки $rowsRead");
                        continue;
                    }

                    $occurredAt = $normalized['occurredAt'];
                    $direction = $normalized['direction'];
                    $amount = $normalized['amount'];
                    $docNumber = $normalized['docNumber'];
                    $description = $normalized['description'];
                    $counterpartyName = $normalized['counterpartyName'];

                    if (!$occurredAt || !$direction || !$amount) {
                        if ($importLog) $this->importLogger->incError($importLog);
                        continue;
                    }

                    // Дедупликация
                    $occurredAtUtc = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
                    $amountMinor = (int) str_replace('.', '', $amount);
                    $dedupeHash = $this->makeDedupeHash(
                        $companyId, $accountId, $occurredAtUtc, $amountMinor, $description ?? ''
                    );

                    if ($this->cashTransactionRepository->existsByCompanyAndDedupe($companyId, $dedupeHash)) {
                        if ($importLog) $this->importLogger->incSkippedDuplicate($importLog);
                        continue;
                    }

                    // Создание
                    $transaction = new CashTransaction(
                        Uuid::uuid4()->toString(),
                        $company, $moneyAccount, $direction, $amount,
                        $normalized['currency'], $occurredAt
                    );
                    $transaction->setDedupeHash($dedupeHash);
                    $transaction->setImportSource('file');
                    $transaction->setDocNumber($docNumber);
                    $transaction->setDescription($description);
                    $transaction->setBookedAt($occurredAt);
                    $transaction->setRawData(['row' => $rowByHeader, 'mapping' => $mapping]);
                    $transaction->setUpdatedAt(new \DateTimeImmutable());

                    if (is_string($docNumber) && trim($docNumber) !== '') {
                        $transaction->setExternalId(trim($docNumber));
                    }

                    if (null !== $counterpartyName) {
                        $transaction->setCounterparty($this->getOrCreateCounterparty($companyId, $counterpartyName, $company));
                    }

                    $this->entityManager->persist($transaction);
                    $created++;

                    if ($importLog) $this->importLogger->incCreated($importLog);

                    if (null === $createdMinDate || $occurredAt < $createdMinDate) $createdMinDate = $occurredAt;
                    if (null === $createdMaxDate || $occurredAt > $createdMaxDate) $createdMaxDate = $occurredAt;

                    $batchCount++;
                    if ($batchCount >= $batchSize) {
                        $this->flushBatch();
                        $batchCount = 0;
                    }
                }
            }

            $this->logToConsole("ИТОГ: Прочитано строк: $rowsRead. Создано записей: $created");

            if ($batchCount > 0) $this->flushBatch();

            if ($created > 0 && null !== $createdMinDate) {
                $today = new \DateTimeImmutable('today');
                $toDate = $createdMaxDate ?? $createdMinDate;
                if ($createdMinDate <= $today) $toDate = $today;
                $this->accountBalanceService->recalculateDailyRange($company, $moneyAccount, $createdMinDate, $toDate);
            }
        } finally {
            $reader->close();
            if ($importLog) {
                $this->importLogger->finish($importLog);
                $this->entityManager->flush();
            }
        }
    }

    private function logToConsole(string $msg): void
    {
        file_put_contents('php://stderr', sprintf("[%s] %s\n", date('H:i:s'), $msg));
    }

    private function resolveFilePath(CashFileImportJob $job): string
    {
        $storageDir = sprintf('%s/var/storage/cash-file-imports', $this->projectDir);
        $fileHash = $job->getFileHash();

        // Сначала ищем по точному совпадению
        $path = "$storageDir/$fileHash";
        if (file_exists($path)) return $path;

        // Потом с расширением из джобы
        $ext = pathinfo($job->getFilename(), PATHINFO_EXTENSION);
        if ($ext && file_exists("$path.$ext")) return "$path.$ext";

        // Потом ищем любой файл с этим хэшем
        $glob = glob("$path.*");
        if (!empty($glob)) return $glob[0];

        throw new \RuntimeException("Файл не найден: $fileHash");
    }

    private function openReaderByExtension(string $filePath): ReaderInterface
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'csv' => (function() use ($filePath) {
                $opt = new CsvOptions();
                $opt->FIELD_DELIMITER = $this->detectCsvDelimiter($filePath);
                $this->logToConsole("CSV разделитель: " . $opt->FIELD_DELIMITER);
                return new CsvReader($opt);
            })(),
            'xlsx' => new XlsxReader(),
            'xls' => new XlsReader(),
            default => throw new \InvalidArgumentException("Неизвестный формат: $ext")
        };
    }

    private function normalizeValue(mixed $value, bool $trim): ?string
    {
        if (null === $value) return null;
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        if (is_bool($value)) return $value ? '1' : '0';
        $str = (string)$value;
        return $trim ? trim($str) : $str;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $h = fopen($path, 'r');
        if (!$h) return ';';

        $lines = [];
        for ($i=0; $i<5; $i++) {
            $l = fgets($h);
            if ($l) $lines[] = $l;
        }
        fclose($h);

        $delims = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
        foreach ($lines as $line) {
            foreach (array_keys($delims) as $d) {
                $delims[$d] += substr_count($line, $d);
            }
        }

        arsort($delims);
        return array_key_first($delims);
    }

    // --- Вспомогательные методы (без изменений логики) ---
    private function normalizePurposeForDedupe(?string $value): string {
        $value = (string) $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }

    private function makeDedupeHash(string $cid, string $accId, \DateTimeImmutable $date, int $amt, string $desc): string {
        return hash('sha256', "$cid|$accId|{$date->format('Y-m-d')}|$amt|" . $this->normalizePurposeForDedupe($desc));
    }

    private function getOrCreateCounterparty(string $cid, string $name, Company $comp): Counterparty {
        $name = trim($name);
        $key = "$cid:" . mb_strtolower($name);
        if (isset($this->counterpartyCache[$key])) return $this->counterpartyCache[$key];

        $exist = $this->counterpartyRepository->findOneBy(['company' => $comp, 'name' => $name]);
        if ($exist) return $this->counterpartyCache[$key] = $exist;

        $cp = new Counterparty(Uuid::uuid4()->toString(), $comp, $name, CounterpartyType::LEGAL_ENTITY);
        $this->entityManager->persist($cp);
        return $this->counterpartyCache[$key] = $cp;
    }

    private function flushBatch(): void {
        $this->entityManager->flush();
        $this->entityManager->clear(CashTransaction::class);
        $this->entityManager->clear(Counterparty::class);
        $this->counterpartyCache = [];
    }
}
