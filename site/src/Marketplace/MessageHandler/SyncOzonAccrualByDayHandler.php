<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonAccrualByDayClientInterface;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
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

    /** Ключ начислений в полезной нагрузке документа. */
    public const PAYLOAD_ACCRUALS = 'accruals';

    /** Ключ справочника услуг `type_id` -> имя в полезной нагрузке документа. */
    public const PAYLOAD_SERVICE_TYPES = 'service_types';

    private const LOCK_TTL_SECONDS = 900;

    /**
     * Сколько документ считается «в обработке». Дольше этого срока PENDING
     * означает не идущую обработку, а потерянное сообщение.
     */
    private const IN_PROGRESS_GRACE_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonAccrualByDayClientInterface $client,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly MarketplaceConnectionRepository $connectionRepository,
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

        // Подключение ищется в границах компании из сообщения, а не по одному
        // идентификатору: иначе подменённое сообщение загрузило бы данные одной
        // компании, а состояние синхронизации переписало бы другой (IDOR).
        $connection = $this->connectionRepository->findByIdAndCompany($message->connectionId, $company);
        if (!$connection instanceof MarketplaceConnection) {
            $this->logger->error('MarketplaceConnection not found for company', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        if (MarketplaceType::OZON !== $connection->getMarketplace()
            || MarketplaceConnectionType::SELLER !== $connection->getConnectionType()
        ) {
            $this->logger->error('Connection is not an Ozon seller connection', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

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

        // Охрана «идёт обработка» ограничена возрастом документа. Без этого
        // единственный сбой отправки оставлял бы день в PENDING навсегда:
        // следующий прогон видел бы статус и пропускал документ, а обработать
        // его уже некому.
        $staleAfter = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', self::IN_PROGRESS_GRACE_SECONDS));

        if (
            null !== $existing
            && in_array($existing->getProcessingStatus(), [PipelineStatus::PENDING, PipelineStatus::RUNNING], true)
            && $existing->getSyncedAt() > $staleAfter
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
            // Справочник услуг забирается вместе с днём: начисления несут только
            // type_id, а разбор услуги в категорию затрат идёт по имени. Хранить
            // его отдельно от документа значило бы разъехаться во времени —
            // документ переобрабатывается и через месяц.
            $serviceTypes = $this->client->fetchServiceTypes($message->companyId);
        } catch (MarketplaceApiException $e) {
            $this->handleApiFailure($e, $message, $connection);

            return;
        }

        $document = $this->storeDocument($company, $existing, $day, $rows, $serviceTypes, $message);

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
            // Проглотить нельзя: документ уже выставлен в PENDING, и следующий
            // прогон пропустил бы его как «в обработке» — день застрял бы
            // навсегда без единого сообщения обработки. Повторяем весь шаг:
            // загрузка и обновление документа идемпотентны.
            $this->logger->warning('Failed to dispatch processing for Ozon accrual by-day, will retry', [
                'company_id' => $message->companyId,
                'raw_document_id' => $document->getId(),
                'date' => $message->date,
                'error' => $e->getMessage(),
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $serviceTypes
     */
    private function storeDocument(
        Company $company,
        ?MarketplaceRawDocument $existing,
        \DateTimeImmutable $day,
        array $rows,
        array $serviceTypes,
        SyncOzonAccrualByDayMessage $message,
    ): MarketplaceRawDocument {
        // Документ несёт и начисления, и справочник. Конвейер разворачивает
        // `accruals` в строки — так же, как разворачивает `result.operations`
        // у легаси-формата.
        $payload = [
            self::PAYLOAD_ACCRUALS => $rows,
            self::PAYLOAD_SERVICE_TYPES => $serviceTypes,
        ];
        // Пустой день сохраняется документом намеренно. Без него «не загружали»
        // и «загрузили, начислений нет» неотличимы — ровно та слепота, что
        // скрыла сбой 09.09.2026 до утреннего разбора.
        if (null !== $existing) {
            $existing->refreshRawData(
                rawData: $payload,
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
        $document->setRawData($payload);
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
            // Retry-After от Ozon передаётся дальше в миллисекундах: повторять
            // раньше, чем разрешил маркетплейс, — гарантированный новый 429.
            $retryDelayMs = $e instanceof MarketplaceRateLimitException && null !== $e->getRetryAfter()
                ? $e->getRetryAfter() * 1000
                : null;

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, $retryDelayMs);
        }

        // Ретраить бесполезно, но и терять день нельзя: Unrecoverable кладёт
        // сообщение в failed-транспорт, откуда его видно и можно перезапустить.
        throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
    }
}
