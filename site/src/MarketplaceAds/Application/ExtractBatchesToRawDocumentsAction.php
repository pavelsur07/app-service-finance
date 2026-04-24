<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ручная точка входа для конвертации OK-батчей нового cron-driven pipeline
 * (Task-11+) в {@see AdRawDocument} и диспатча
 * {@see ProcessAdRawDocumentMessage} в существующий Messenger-пайплайн.
 *
 * Task-12-test: это не автоматизация, а «dry-run» кнопка для проверки
 * парсинга перед переносом в Poller (Task-13). После успешного теста
 * удаление zip-файлов / обнуление `storage_path` будет сделано отдельно.
 *
 * Идемпотентность на уровне (company, batch, filename):
 *  - raw_payload имеет префикс-метку `batch_id=<uuid>\nfilename=<name>\n---\n<csv>`;
 *  - повторный клик «Обработать» находит существующий документ через
 *    {@see AdRawDocumentRepository::findByBatchAndFilename()} → skipped++;
 *  - старое UNIQUE `(company_id, marketplace, report_date)` снято миграцией
 *    Version20260424120000, так как в batch'е может быть до 10 CSV на один день.
 *
 * IDOR-guard: batch'и фильтруются через `findDownloadableByJobId(jobId, companyId)`.
 * Чужой `jobId` → пустой список → «0 processed».
 */
final readonly class ExtractBatchesToRawDocumentsAction
{
    public function __construct(
        private AdScheduledBatchRepository $batchRepo,
        private AdRawDocumentRepository $rawDocRepo,
        private StorageService $storageService,
        private EntityManagerInterface $em,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Распаковывает все OK-батчи job'а, создаёт AdRawDocument на каждый CSV
     * и диспатчит ProcessAdRawDocumentMessage для async-обработки.
     *
     * Используется ручной кнопкой «Обработать» (safety-net: если auto-extract
     * в Poller'е Task-13a упал, кнопка позволяет обработать job целиком).
     *
     * @return array{processed: int, skipped: int, errors: int}
     *     processed — сколько новых AdRawDocument создано (и Messages диспатчнуто);
     *     skipped   — сколько уже существовавших (company, batch, filename) пар найдено;
     *     errors    — сколько batch'ей упало на extraction (распаковка/чтение файла).
     */
    public function __invoke(string $jobId, string $companyId): array
    {
        $batches = $this->batchRepo->findDownloadableByJobId($jobId, $companyId);

        $skipped = 0;
        $errors = 0;
        /** @var list<AdRawDocument> $allPersisted */
        $allPersisted = [];

        foreach ($batches as $batch) {
            try {
                $result = $this->processBatchInternal($batch);
                $skipped += $result['skipped'];
                foreach ($result['persistedDocs'] as $doc) {
                    $allPersisted[] = $doc;
                }
            } catch (\Throwable $e) {
                // processBatchInternal уже detach'нул свои частично-persisted
                // документы — UoW чист относительно этого batch'а, можно идти
                // дальше без утечки в последующий flush.
                ++$errors;
                $this->logger->error('Batch extraction failed', [
                    'jobId' => $jobId,
                    'companyId' => $companyId,
                    'batchId' => $batch->getId(),
                    'storagePath' => $batch->getStoragePath(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush до dispatch: handler начнёт работать, как только worker подхватит
        // message, и findByIdAndCompany должен увидеть свежий AdRawDocument в БД.
        // Без этого при in-memory транспорте/быстром worker'е handler увидит «not found».
        if ([] !== $allPersisted) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                // Flush упал — detach всех, чтобы не «перетекли» в последующие
                // operations на том же EM (например, следующий HTTP-запрос в
                // shared EM контексте долгой Messenger-consumer сессии).
                foreach ($allPersisted as $doc) {
                    $this->em->detach($doc);
                }
                throw $e;
            }
        }

        foreach ($allPersisted as $doc) {
            $this->messageBus->dispatch(new ProcessAdRawDocumentMessage(
                $companyId,
                $doc->getId(),
            ));
        }

        $processed = count($allPersisted);

        $this->logger->info('ExtractBatchesToRawDocumentsAction: finished', [
            'jobId' => $jobId,
            'companyId' => $companyId,
            'batchesSeen' => count($batches),
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Обрабатывает один batch (вызывается из Poller'а сразу после успешного
     * скачивания, Task-13a).
     *
     * Отличия от {@see __invoke}:
     *  - точечная обработка одного уже-OK батча, без прохода по job'у;
     *  - исключения на extraction пробрасываются наружу — Poller ловит
     *    `\Throwable` сам и логирует как «auto-extract failed, batch остаётся
     *    для ручной переобработки». Batch не переводится в FAILED: файл на
     *    диске есть, кнопка «Обработать» сработает как fallback;
     *  - идемпотентность: если AdRawDocument для (company, batch, filename)
     *    уже существует → skipped++.
     *
     * UoW-изоляция (review PR #1654): `processBatchInternal` персистит
     * AdRawDocument'ы в цикле по CSV. Если в середине цикла бросается
     * exception (битый zip, сбой `createOrFindRawDocument` на N-м CSV после
     * первых K успешных persist'ов, сбой `em->flush()`), ранее-persisted
     * entity остаются в UoW. Следующий batch в tick'е Poller'а вызвал бы
     * `em->flush()` и эти «orphan»-документы ушли бы в БД вне своего
     * контекста. Защита — **surgical `em->detach()`** только по нашим
     * свежеперсистированным `AdRawDocument`-ссылкам:
     *  - `em->clear()` не подходит: pre-fetched managed AdScheduledBatch'и
     *    в Poller'е (из `findAllInFlight()`) были бы detach'нуты, последующие
     *    state-transitions (setState/setLastError/setFinishedAt + flush)
     *    silently не дошли бы до БД. Doctrine ORM 3.x больше не поддерживает
     *    selective `clear(entityName)`, поэтому detach — единственный
     *    безопасный вариант.
     *  - detach per-entity: остальной UoW (AdScheduledBatch и др.) остаётся
     *    нетронутым, Poller продолжает работать как раньше.
     *
     * @return array{processed: int, skipped: int}
     */
    public function processBatch(AdScheduledBatch $batch): array
    {
        $result = $this->processBatchInternal($batch);
        /** @var list<AdRawDocument> $persisted */
        $persisted = $result['persistedDocs'];

        try {
            // Flush только если реально есть что коммитить — избегаем
            // noop-flush на batch'ах, где все CSV уже processed (skipped).
            if ([] !== $persisted) {
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            // Flush failed (DB-constraint / connection) — detach всех, чтобы
            // orphan'ы не ушли в следующий flush.
            foreach ($persisted as $doc) {
                $this->em->detach($doc);
            }
            throw $e;
        }

        foreach ($persisted as $doc) {
            $this->messageBus->dispatch(new ProcessAdRawDocumentMessage(
                $batch->getCompanyId(),
                $doc->getId(),
            ));
        }

        $this->logger->info('ExtractBatchesToRawDocumentsAction::processBatch: finished', [
            'companyId' => $batch->getCompanyId(),
            'batchId' => $batch->getId(),
            'processed' => count($persisted),
            'skipped' => $result['skipped'],
        ]);

        return [
            'processed' => count($persisted),
            'skipped' => $result['skipped'],
        ];
    }

    /**
     * Общая логика: извлечь CSV → создать/найти AdRawDocument → собрать
     * список свежеперсистированных entity-ссылок. Flush и dispatch делает
     * вызывающий, чтобы __invoke мог объединить flush по нескольким batch'ам
     * в одну транзакцию.
     *
     * UoW-leak guard: если бросается exception в цикле, detach'ит уже
     * persisted AdRawDocument'ы (см. процедурное обоснование в
     * {@see processBatch}). Rethrow оригинальное исключение — вызывающие
     * ветки (__invoke / processBatch) логируют / пробрасывают дальше.
     *
     * @return array{skipped: int, persistedDocs: list<AdRawDocument>}
     *
     * @throws \Throwable всё, что бросила extractCsvsFromBatch / createOrFindRawDocument
     */
    private function processBatchInternal(AdScheduledBatch $batch): array
    {
        $companyId = $batch->getCompanyId();
        $skipped = 0;
        /** @var list<AdRawDocument> $persisted */
        $persisted = [];

        try {
            $csvs = $this->extractCsvsFromBatch($batch);
            foreach ($csvs as $filename => $csvContent) {
                $doc = $this->createOrFindRawDocument(
                    $companyId,
                    $batch,
                    (string) $filename,
                    $csvContent,
                );
                if (null === $doc) {
                    ++$skipped;
                    continue;
                }
                $persisted[] = $doc;
            }

            return [
                'skipped' => $skipped,
                'persistedDocs' => $persisted,
            ];
        } catch (\Throwable $e) {
            foreach ($persisted as $doc) {
                $this->em->detach($doc);
            }
            throw $e;
        }
    }

    /**
     * Читает файл batch'а с диска и возвращает CSV-контент по именам файлов.
     *
     * Поддерживает две формы `storage_path`:
     *  - одиночный `*.csv` (batch из одной кампании, Ozon вернул plain-CSV);
     *  - `*.zip` с одним или несколькими `*.csv` внутри (обычный случай для 10 кампаний).
     *
     * Не-CSV файлы внутри zip игнорируются (защита от случайных readme/manifest
     * файлов, которых в нормальном отчёте Ozon не бывает, но встречаются в
     * edge-случаях).
     *
     * @return array<string, string> filename => CSV bytes
     *
     * @throws \RuntimeException если файл отсутствует на диске / zip битый / расширение не csv|zip
     */
    public function extractCsvsFromBatch(AdScheduledBatch $batch): array
    {
        $storagePath = $batch->getStoragePath();
        if (null === $storagePath || '' === $storagePath) {
            throw new \RuntimeException('Batch has no storage_path');
        }

        $absolutePath = $this->storageService->getAbsolutePath($storagePath);
        if (!file_exists($absolutePath)) {
            throw new \RuntimeException('Batch file missing on disk: '.$absolutePath);
        }

        $extension = strtolower(pathinfo($storagePath, \PATHINFO_EXTENSION));

        if ('csv' === $extension) {
            $filename = basename($storagePath);
            $content = file_get_contents($absolutePath);
            if (false === $content) {
                throw new \RuntimeException('Cannot read csv file: '.$absolutePath);
            }

            return [$filename => $content];
        }

        if ('zip' === $extension) {
            return $this->extractCsvsFromZip($absolutePath);
        }

        throw new \RuntimeException('Unknown batch file extension: '.$extension);
    }

    /**
     * @return array<string, string>
     *
     * @throws \RuntimeException если zip не открывается
     */
    private function extractCsvsFromZip(string $absolutePath): array
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($absolutePath);
        if (true !== $openResult) {
            throw new \RuntimeException(sprintf(
                'Cannot open zip: %s (code %d)',
                $absolutePath,
                (int) $openResult,
            ));
        }

        try {
            $result = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (false === $stat) {
                    continue;
                }
                $name = (string) $stat['name'];
                if (!str_ends_with(strtolower($name), '.csv')) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if (false === $content) {
                    continue;
                }
                $result[$name] = $content;
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Идемпотентно создаёт AdRawDocument или возвращает null если уже существует
     * документ с тем же (companyId, batchId, filename).
     *
     * Возвращает **entity-ссылку** (а не id), чтобы caller мог передать её в
     * `em->detach()` при UoW-leak guard'е (см. {@see processBatchInternal}).
     *
     * `raw_payload` получает префикс `batch_id=<uuid>\nfilename=<name>\n---\n<csv>` —
     * маркер служит ключом идемпотентности ({@see AdRawDocumentRepository::findByBatchAndFilename}).
     * reportDate берётся из `batch.dateFrom` (batch нового pipeline'а — один день).
     */
    private function createOrFindRawDocument(
        string $companyId,
        AdScheduledBatch $batch,
        string $filename,
        string $csvContent,
    ): ?AdRawDocument {
        $existing = $this->rawDocRepo->findByBatchAndFilename(
            $companyId,
            $batch->getId(),
            $filename,
        );

        if (null !== $existing) {
            return null;
        }

        $marketplace = $this->resolveMarketplace($batch);
        $reportDate = $batch->getDateFrom();
        $rawPayload = self::buildRawPayload($batch->getId(), $filename, $csvContent);

        $doc = new AdRawDocument(
            $companyId,
            $marketplace,
            $reportDate,
            $rawPayload,
        );

        $this->rawDocRepo->save($doc);

        return $doc;
    }

    private function resolveMarketplace(AdScheduledBatch $batch): MarketplaceType
    {
        $value = $batch->getMarketplace();

        return MarketplaceType::tryFrom($value) ?? throw new \RuntimeException(sprintf(
            'Unknown marketplace "%s" on batch %s',
            $value,
            $batch->getId(),
        ));
    }

    public static function buildRawPayload(string $batchId, string $filename, string $csvContent): string
    {
        return sprintf(
            "batch_id=%s\nfilename=%s\n---\n%s",
            $batchId,
            $filename,
            $csvContent,
        );
    }
}
