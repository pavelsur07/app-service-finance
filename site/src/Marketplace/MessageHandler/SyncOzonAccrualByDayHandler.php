<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonAccrualByDayClientInterface;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Загружает начисления Ozon за день и сохраняет их сырьём.
 *
 * Пришёл на смену SyncOzonReportHandler, чей источник Ozon снял 09.09.2026.
 * Старый обработчик остаётся: 967 документов формата v3 должны
 * переобрабатываться.
 *
 * Документ пишется с собственным `document_type = accrual_by_day`. Разделить
 * типы обязывает частичный уникальный индекс
 * `uniq_marketplace_raw_documents_active_period`: он запрещает два активных
 * документа Ozon `sales_report` на один день, поэтому легаси-документ и
 * документ by-day не могут делить тип, даже различаясь `api_endpoint`.
 */
#[AsMessageHandler]
final class SyncOzonAccrualByDayHandler
{
    public const DOCUMENT_TYPE = 'accrual_by_day';

    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonAccrualByDayClientInterface $client,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function __invoke(SyncOzonAccrualByDayMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            sprintf('marketplace_accrual_by_day_%s_%s', $message->companyId, $message->date),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon accrual by-day sync already in progress, skipping', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'date' => $message->date,
            ]);

            return;
        }

        try {
            $this->process($message);
        } finally {
            $lock->release();
        }
    }

    private function process(SyncOzonAccrualByDayMessage $message): void
    {
        $company = $this->em->find(Company::class, $message->companyId);
        if (!$company instanceof Company) {
            $this->logger->error('Company not found for Ozon accrual by-day sync', [
                'company_id' => $message->companyId,
            ]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $message->connectionId);
        if (!$connection instanceof MarketplaceConnection) {
            $this->logger->error('MarketplaceConnection not found', ['connection_id' => $message->connectionId]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('Ozon connection is inactive, skipping', ['connection_id' => $message->connectionId]);

            return;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->date, new \DateTimeZone('Europe/Moscow'));
        if (false === $day || $day->format('Y-m-d') !== $message->date) {
            // Невалидный вход — не системный сбой: warning и выход без ретрая.
            $this->logger->warning('Invalid date in SyncOzonAccrualByDayMessage, skipping', [
                'company_id' => $message->companyId,
                'date' => $message->date,
            ]);

            return;
        }

        $existing = $this->rawDocumentRepository->findActiveExactDayDocuments(
            $company,
            MarketplaceType::OZON,
            self::DOCUMENT_TYPE,
            $day,
        )[0] ?? null;

        if (
            null !== $existing
            && in_array($existing->getProcessingStatus(), [PipelineStatus::PENDING, PipelineStatus::RUNNING], true)
        ) {
            $this->logger->info('Skipping Ozon accrual refresh: pipeline is still in progress', [
                'company_id' => $message->companyId,
                'raw_document_id' => $existing->getId(),
                'date' => $message->date,
            ]);

            return;
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            $rows = $this->client->fetchDay($message->companyId, $day);
        } catch (MarketplaceApiException $e) {
            $this->handleApiFailure($e, $message, $connection);

            return;
        }

        $document = $this->storeDocument($company, $existing, $day, $rows, $message);

        $connection->markSyncSuccess();
        $this->em->flush();

        try {
            $this->messageBus->dispatch(new ProcessDayReportMessage(
                companyId: $message->companyId,
                rawDocumentId: $document->getId(),
                connectionId: $message->connectionId,
                marketplace: MarketplaceType::OZON->value,
                businessDate: $message->date,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch processing for Ozon accrual by-day', [
                'company_id' => $message->companyId,
                'raw_document_id' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeDocument(
        Company $company,
        ?MarketplaceRawDocument $existing,
        \DateTimeImmutable $day,
        array $rows,
        SyncOzonAccrualByDayMessage $message,
    ): MarketplaceRawDocument {
        // Пустой день сохраняется документом намеренно. Без него «не загружали»
        // и «загрузили, начислений нет» неотличимы — ровно та слепота, что
        // скрыла сбой 09.09.2026 до утреннего разбора.
        if (null !== $existing) {
            $existing->refreshRawData(
                rawData: $rows,
                apiEndpoint: MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value,
                recordsCount: count($rows),
            );
            $this->em->flush();

            $this->logger->info('Ozon accrual by-day document refreshed', [
                'company_id' => $message->companyId,
                'raw_document_id' => $existing->getId(),
                'date' => $message->date,
                'rows' => count($rows),
            ]);

            return $existing;
        }

        $document = new MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            self::DOCUMENT_TYPE,
        );
        $document->setPeriodFrom($day);
        $document->setPeriodTo($day);
        $document->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $document->setRawData($rows);
        $document->setRecordsCount(count($rows));

        $this->em->persist($document);
        $this->em->flush();

        $this->logger->info('Ozon accrual by-day document saved', [
            'company_id' => $message->companyId,
            'raw_document_id' => $document->getId(),
            'date' => $message->date,
            'rows' => count($rows),
        ]);

        return $document;
    }

    private function handleApiFailure(
        MarketplaceApiException $e,
        SyncOzonAccrualByDayMessage $message,
        MarketplaceConnection $connection,
    ): void {
        $transient = $e instanceof MarketplaceRateLimitException || $e instanceof MarketplaceTemporaryApiException;

        $context = [
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
            'date' => $message->date,
            'http_status' => $e->getStatusCode(),
            'response_excerpt' => $e->getResponseExcerpt(),
        ];

        if ($transient) {
            $this->logger->warning('Ozon accrual by-day: temporary API failure, will retry', $context);
        } else {
            $this->logger->error('Ozon accrual by-day sync failed', $context);
        }

        $connection->markSyncFailed($e->getMessage());
        $this->em->flush();

        if ($transient) {
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        // Ретраить бесполезно, но и терять день нельзя: Unrecoverable кладёт
        // сообщение в failed-транспорт, откуда его видно и можно перезапустить.
        throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
    }
}
