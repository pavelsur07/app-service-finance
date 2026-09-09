<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonReportMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Загружает сырые данные Ozon за конкретную дату и сохраняет MarketplaceRawDocument.
 * После успешной загрузки диспатчит ProcessDayReportMessage для автозапуска pipeline.
 */
#[AsMessageHandler]
final class SyncOzonReportHandler
{
    private const LOCK_TTL_SECONDS = 300;

    /**
     * Потолок фрагмента тела ответа Ozon в контексте лога.
     *
     * CLAUDE.md, «Логирование», запрещает писать тело ответа внешних API.
     * Исключение здесь узкое и намеренное: только конверт ОШИБКИ неуспешного
     * запроса и только обрезанный. Инцидент 09.09.2026 показал цену обратного:
     * ночной прогон упал по всем кабинетам, в логе осталось
     * «HTTP/2 400 returned for ...», и причина 400 не устанавливалась вообще.
     * Успешные ответы в лог по-прежнему не попадают.
     */
    public const ERROR_EXCERPT_LIMIT = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function __invoke(SyncOzonReportMessage $message): void
    {
        $companyId = $message->companyId;
        $connectionId = $message->connectionId;
        $dateKey = $message->date ?? (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        $lock = $this->lockFactory->createLock(
            'marketplace_sync_'.$companyId.'_ozon_'.$dateKey,
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon sync already in progress, skipping', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
            ]);

            return;
        }

        try {
            $this->process($companyId, $connectionId, $message->date);
        } finally {
            $lock->release();
        }
    }

    private function process(string $companyId, string $connectionId, ?string $date = null): void
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company) {
            $this->logger->error('Company not found for Ozon sync', ['company_id' => $companyId]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
        if (!$connection) {
            $this->logger->error('MarketplaceConnection not found', ['connection_id' => $connectionId]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('Ozon connection is inactive, skipping', ['connection_id' => $connectionId]);

            return;
        }

        $timezone = new \DateTimeZone('Europe/Moscow');

        if (null !== $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
            if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
                // Невалидный вход → skip. Это не системный сбой → warning (не в GlitchTip).
                $this->logger->warning('Invalid date in SyncOzonReportMessage, skipping', [
                    'company_id' => $companyId,
                    'date' => $date,
                ]);

                return;
            }
            $fromDate = $parsed;
            $toDate = $parsed;
        } else {
            $toDate = new \DateTimeImmutable('yesterday', $timezone);
            $fromDate = $toDate;
        }

        // existingDoc используется для двух сценариев:
        // 1) refresh завершённого документа (status null/completed), чтобы дозагрузить
        //    поздние корректировки из Ozon без создания дубля;
        // 2) skip refresh для in-flight pipeline (pending/running), чтобы не обновлять
        //    payload во время обработки и не получить смешанное состояние шагов.
        // FAILED-документы в выборку не попадают — для них retry создаёт новый.
        $existingDocs = $this->rawDocumentRepository->findActiveExactDayDocuments(
            $company,
            MarketplaceType::OZON,
            'sales_report',
            $fromDate,
        );
        $existingDoc = $existingDocs[0] ?? null;

        if (count($existingDocs) > 1) {
            $this->logger->warning('Multiple active Ozon raw documents found for day, using latest as canonical', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
                'date' => $fromDate->format('Y-m-d'),
                'raw_document_ids' => array_map(
                    static fn (MarketplaceRawDocument $rawDocument): string => $rawDocument->getId(),
                    $existingDocs,
                ),
            ]);
        }

        if (
            null !== $existingDoc
            && in_array($existingDoc->getProcessingStatus(), [PipelineStatus::PENDING, PipelineStatus::RUNNING], true)
        ) {
            $this->logger->info('Skipping Ozon refresh: pipeline is still in progress for this raw document', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
                'raw_document_id' => $existingDoc->getId(),
                'status' => $existingDoc->getProcessingStatus()?->value,
                'date' => $fromDate->format('Y-m-d'),
            ]);

            return;
        }

        $connection->markSyncStarted();
        $this->em->flush();

        $rawDocId = null;

        try {
            $adapter = $this->adapterRegistry->get(MarketplaceType::OZON);

            $rawData = $adapter->fetchRawReport($company, $fromDate, $toDate);

            if (empty($rawData)) {
                $this->logger->info('Ozon API returned empty report', [
                    'company_id' => $companyId,
                    'period' => $fromDate->format('Y-m-d').' - '.$toDate->format('Y-m-d'),
                ]);
                $connection->markSyncSuccess();
                $this->em->flush();

                return;
            }

            if (null !== $existingDoc) {
                $existingDoc->refreshRawData(
                    rawData: $rawData,
                    apiEndpoint: $adapter->getApiEndpointName(),
                    recordsCount: count($rawData),
                );

                $this->em->flush();

                $rawDocId = $existingDoc->getId();

                $this->logger->info('Ozon raw report refreshed', [
                    'company_id' => $companyId,
                    'connection_id' => $connectionId,
                    'raw_doc_id' => $rawDocId,
                    'records_count' => count($rawData),
                    'period' => $fromDate->format('Y-m-d').' - '.$toDate->format('Y-m-d'),
                ]);
            } else {
                $rawDoc = new MarketplaceRawDocument(
                    Uuid::uuid4()->toString(),
                    $company,
                    MarketplaceType::OZON,
                    'sales_report',
                );
                $rawDoc->setPeriodFrom($fromDate);
                $rawDoc->setPeriodTo($toDate);
                $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
                $rawDoc->setRawData($rawData);
                $rawDoc->setRecordsCount(count($rawData));

                $this->em->persist($rawDoc);
                $this->em->flush();

                $rawDocId = $rawDoc->getId();

                $this->logger->info('Ozon raw report saved', [
                    'company_id' => $companyId,
                    'connection_id' => $connectionId,
                    'raw_doc_id' => $rawDocId,
                    'records_count' => count($rawData),
                    'period' => $fromDate->format('Y-m-d').' - '.$toDate->format('Y-m-d'),
                ]);
            }

            $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
            $connection->markSyncSuccess();
            $this->em->flush();
        } catch (\Throwable $e) {
            $transient = $this->isTransient($e);
            $context = [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
                // Окно прогона — 14 дней на подключение, поэтому без дня
                // по логу не понять, какой именно отчёт не загрузился.
                'date' => $fromDate->format('Y-m-d'),
                'error' => $e->getMessage(),
            ] + $this->httpErrorContext($e);

            if ($transient) {
                // Ожидаемо и повторяемо (429/5xx/таймаут) → warning (не в GlitchTip) + retry Messenger.
                $this->logger->warning('Ozon daily sync: temporary API failure, will retry', $context);
            } else {
                $this->logger->error('Ozon daily sync failed', $context);
            }

            try {
                $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
                if ($connection) {
                    // ponytail: при transient статус мигнёт failed→success между ретраями (~минута);
                    // отдельный "retrying"-статус — только если это начнёт мешать в UI.
                    $connection->markSyncFailed($e->getMessage());
                    $this->em->flush();
                }
            } catch (\Throwable $inner) {
                $this->logger->error('Failed to save Ozon sync error status', [
                    'error' => $inner->getMessage(),
                ]);
            }

            if ($transient) {
                // Retry по стратегии async_sync: 10с → 20с → 40с, дальше failed-транспорт.
                throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }

            // Ретраить бесполезно, но и терять день нельзя: раньше здесь стоял
            // `return`, сообщение подтверждалось, и пропуск не оставлял следа
            // ни в очереди, ни в failed-транспорте. Unrecoverable ретраи не
            // включает и кладёт сообщение в `failed`, откуда его видно и можно
            // перезапустить через messenger:failed:retry.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        try {
            $this->messageBus->dispatch(new ProcessDayReportMessage(
                companyId: $companyId,
                rawDocumentId: $rawDocId,
            ));

            $this->logger->info('Dispatched auto-processing for Ozon day report', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch auto-processing for Ozon', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Статус и обрезанный конверт ошибки Ozon — то, чего не хватало, чтобы
     * отличить «сломался контракт API» от «протух ключ» по одному логу.
     *
     * @return array{http_status?: int, response_excerpt?: string}
     */
    private function httpErrorContext(\Throwable $e): array
    {
        if (!$e instanceof HttpExceptionInterface) {
            return [];
        }

        $response = $e->getResponse();

        try {
            // false — не бросать повторно на 4xx/5xx: тело нужно именно от них.
            $excerpt = mb_substr($response->getContent(false), 0, self::ERROR_EXCERPT_LIMIT);
        } catch (\Throwable) {
            $excerpt = '(unavailable)';
        }

        return [
            'http_status' => $response->getStatusCode(),
            'response_excerpt' => $excerpt,
        ];
    }

    /**
     * OzonAdapter бросает сырые исключения Symfony HttpClient (не типизированные
     * Marketplace*Exception, как WB-клиент) — классифицируем по ним.
     */
    private function isTransient(\Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getResponse()->getStatusCode();

            return 429 === $status || $status >= 500;
        }

        // Сетевой сбой / таймаут — ответа нет вообще.
        return $e instanceof TransportExceptionInterface;
    }
}
