<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Async-обработчик {@see FetchOzonAdStatisticsMessage}.
 *
 * Логика одного чанка:
 *  1) найти AdLoadJob (IDOR по company_id); если job удалён или в терминальном
 *     статусе — no-op;
 *  2) вызвать {@see OzonAdClient::fetchAdStatisticsRange};
 *  3) для каждого дня результата — upsert AdRawDocument (новый → save,
 *     существующий → updatePayload() — тот сам сбрасывает status в DRAFT);
 *  4) единый flush на весь чанк;
 *  5) атомарный incrementLoadedDays($jobId, chunkDays - skippedDays) — raw SQL,
 *     минуя UoW (parallel-safe с другими воркерами того же job'а). Считаем
 *     по покрытию чанка, а не по числу документов: Ozon может вернуть меньше
 *     дней, чем запросили (дни без кампаний), и такие «пустые» дни всё равно
 *     должны учитываться как обработанные, иначе прогресс зависнет ниже 100%;
 *  6) dispatch ProcessAdRawDocumentMessage за каждый документ — уже ПОСЛЕ
 *     flush, чтобы следующий handler увидел документ в БД.
 *
 * Политика ошибок:
 *  - \InvalidArgumentException (range > 62 дней / from > to): permanent bug
 *    вызывающей стороны → markFailed(job) + UnrecoverableMessageHandlingException.
 *  - OzonPermanentApiException (403, missing credentials): permanent →
 *    markFailed(job) + UnrecoverableMessageHandlingException.
 *  - Прочие \Throwable (5xx, сеть, JSON-ошибки): transient → rethrow
 *    (Messenger ретраит по стратегии async). Когда retry'и исчерпаются,
 *    message уйдёт в failed-транспорт — его разбирает оператор.
 *
 * Порядок операций строгий: save() → flush() → incrementLoadedDays() →
 * dispatch(ProcessAdRawDocumentMessage). Иначе ProcessAdRawDocumentHandler
 * может стартовать до появления документа в БД.
 */
#[AsMessageHandler]
final class FetchOzonAdStatisticsHandler
{
    public function __construct(
        private readonly OzonAdClient $ozonAdClient,
        private readonly AdRawDocumentRepository $adRawDocumentRepository,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdChunkProgressRepositoryInterface $adChunkProgressRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly AppLogger $logger,
    ) {
    }

    public function __invoke(FetchOzonAdStatisticsMessage $message): void
    {
        $job = $this->adLoadJobRepository->findByIdAndCompany(
            $message->jobId,
            $message->companyId,
        );

        if (null === $job) {
            $this->logger->warning('AdLoadJob не найден при загрузке Ozon-чанка, сообщение проигнорировано', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
            ]);

            return;
        }

        if ($job->getStatus()->isTerminal()) {
            $this->logger->info('AdLoadJob уже завершён, загрузка чанка пропущена', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'status' => $job->getStatus()->value,
            ]);

            return;
        }

        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateFrom);
        $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateTo);

        // Round-trip сравнение с исходной строкой ловит календарно-невалидные
        // даты вроде 2026-02-31 — createFromFormat для них молча возвращает
        // нормализованный DateTimeImmutable (2026-03-03), а не false, из-за
        // чего без этой проверки handler грузил бы не тот диапазон.
        if (
            false === $dateFrom
            || false === $dateTo
            || $dateFrom->format('Y-m-d') !== $message->dateFrom
            || $dateTo->format('Y-m-d') !== $message->dateTo
        ) {
            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                sprintf('Invalid date format in message: from=%s, to=%s', $message->dateFrom, $message->dateTo),
            );

            throw new UnrecoverableMessageHandlingException(sprintf(
                'FetchOzonAdStatisticsMessage: invalid date format (from=%s, to=%s)',
                $message->dateFrom,
                $message->dateTo,
            ));
        }

        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);
        $chunkDays = (int) $dateFrom->diff($dateTo)->days + 1;

        try {
            $result = $this->ozonAdClient->fetchAdStatisticsRange(
                $message->companyId,
                $dateFrom,
                $dateTo,
            );
        } catch (\InvalidArgumentException $e) {
            // Диапазон > 62 дней или from > to — баг вызывающего кода,
            // ретраить бессмысленно.
            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                'Invalid date range: '.$e->getMessage(),
            );

            throw new UnrecoverableMessageHandlingException(
                'FetchOzonAdStatisticsMessage: invalid date range — '.$e->getMessage(),
                0,
                $e,
            );
        } catch (OzonPermanentApiException $e) {
            // 403 / missing credentials — permanent denial, ретраить бессмысленно.
            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                'Ozon API permanent failure: '.$e->getMessage(),
            );

            throw new UnrecoverableMessageHandlingException(
                'FetchOzonAdStatisticsMessage: Ozon permanent failure — '.$e->getMessage(),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            // Сетевые сбои / 5xx / JSON-ошибки — transient, Messenger сделает retry.
            $this->logger->error('Transient failure loading Ozon ad statistics chunk', $e, [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'dateFrom' => $message->dateFrom,
                'dateTo' => $message->dateTo,
                'chunkDays' => $chunkDays,
            ]);

            throw $e;
        }

        /** @var list<AdRawDocument> $documents */
        $documents = [];
        $skippedDays = 0;

        foreach ($result as $dateString => $payload) {
            $reportDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dateString);
            if (false === $reportDate) {
                // OzonAdClient формирует ключи как Y-m-d; некорректный ключ — это баг клиента,
                // но не повод ронять весь чанк. Логируем и пропускаем один день.
                ++$skippedDays;
                $this->logger->error('Invalid date key in Ozon result, day skipped', null, [
                    'jobId' => $message->jobId,
                    'companyId' => $message->companyId,
                    'dateKey' => $dateString,
                ]);
                continue;
            }

            $json = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (false === $json) {
                ++$skippedDays;
                $this->logger->error('json_encode failed for Ozon day payload, day skipped', null, [
                    'jobId' => $message->jobId,
                    'companyId' => $message->companyId,
                    'date' => $reportDate->format('Y-m-d'),
                    'jsonError' => json_last_error_msg(),
                ]);
                continue;
            }

            $existing = $this->adRawDocumentRepository->findByMarketplaceAndDate(
                $message->companyId,
                MarketplaceType::OZON->value,
                $reportDate,
            );

            if (null === $existing) {
                $doc = new AdRawDocument($message->companyId, MarketplaceType::OZON, $reportDate, $json);
                $this->adRawDocumentRepository->save($doc);
                $documents[] = $doc;
            } else {
                // updatePayload() сам сбрасывает status в DRAFT и обновляет updatedAt —
                // дополнительный resetToDraft() не нужен и привёл бы к двойному setter'у.
                $existing->updatePayload($json);
                $documents[] = $existing;
            }
        }

        $this->entityManager->flush();

        // Идемпотентная фиксация чанка — ПЕРЕД инкрементом счётчиков дней.
        // При Messenger retry markChunkCompleted вернёт false (запись уже есть),
        // и мы пропустим инкременты, не удвоив loaded/failed days.
        // permanent/transient ошибки до сюда не доходят (rethrow / Unrecoverable выше).
        $marked = $this->adChunkProgressRepository->markChunkCompleted(
            $message->jobId,
            $message->companyId,
            $dateFrom,
            $dateTo,
        );

        if (!$marked) {
            $this->logger->info('chunk already marked completed', [
                'job_id' => $message->jobId,
                'company_id' => $message->companyId,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
            ]);
        } else {
            // loaded_days считаем по покрытию чанка (chunkDays - skippedDays), а не
            // по count($documents): Ozon легитимно возвращает меньше дней, чем
            // запросили, если за какие-то дни вообще нет кампаний. Если брать
            // count($documents), такие «пустые» дни навсегда останутся
            // непосчитанными в прогрессе, и loaded не дорастёт до total.
            $loadedDelta = $chunkDays - $skippedDays;
            if ($loadedDelta > 0) {
                $this->adLoadJobRepository->incrementLoadedDays(
                    $message->jobId,
                    $message->companyId,
                    $loadedDelta,
                );
            }

            if ($skippedDays > 0) {
                // Per-document FAILED-статус AdRawDocument — источник правды по
                // неуспехам; здесь оставляем предупреждение для наблюдаемости чанка.
                $this->logger->warning('Ozon ad chunk had skipped days', [
                    'jobId' => $message->jobId,
                    'companyId' => $message->companyId,
                    'dateFrom' => $message->dateFrom,
                    'dateTo' => $message->dateTo,
                    'skippedDays' => $skippedDays,
                ]);
            }
        }

        foreach ($documents as $doc) {
            $this->messageBus->dispatch(new ProcessAdRawDocumentMessage(
                $message->companyId,
                $doc->getId(),
            ));
        }

        $this->logger->info('Ozon ad statistics chunk processed', [
            'jobId' => $message->jobId,
            'companyId' => $message->companyId,
            'dateFrom' => $message->dateFrom,
            'dateTo' => $message->dateTo,
            'chunkDays' => $chunkDays,
            'documentsUpserted' => count($documents),
            'daysLoaded' => $marked ? $chunkDays - $skippedDays : 0,
            'daysSkipped' => $skippedDays,
            'duplicate' => !$marked,
        ]);
    }
}
