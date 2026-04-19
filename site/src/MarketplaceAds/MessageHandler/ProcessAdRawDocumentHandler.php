<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepositoryInterface;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async-обработчик {@see ProcessAdRawDocumentMessage}.
 *
 * Контракт error-flow (per-document FAILED):
 *  - исключение из Action → документ помечается FAILED с processing_error,
 *    исключение пере­брасывается для retry-стратегии Messenger;
 *  - Action оставил документ в DRAFT (частичная неудача — например, нет
 *    листингов по части parentSku) → handler сам помечает документ FAILED
 *    и возвращается БЕЗ throw: повторный запуск приведёт к тому же результату;
 *  - {@see AdRawDocumentAlreadyProcessedException} (race / документ удалён) —
 *    поглощается, финализация job'а не запускается (документ уже не «наш»).
 *
 * Финализация AdLoadJob:
 *  - после любого терминального исхода (PROCESSED/FAILED) handler пытается
 *    финализировать job, который покрывает дату документа; сама логика
 *    финализации вынесена в {@see AdLoadJobFinalizer} и шарится с
 *    FetchOzonAdStatisticsHandler (для кейса «0 документов за чанк»).
 */
#[AsMessageHandler]
final class ProcessAdRawDocumentHandler
{
    public function __construct(
        private readonly ProcessAdRawDocumentAction $action,
        private readonly AdRawDocumentRepositoryInterface $adRawDocumentRepository,
        private readonly AdLoadJobRepositoryInterface $adLoadJobRepository,
        private readonly AdLoadJobFinalizer $adLoadJobFinalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppLogger $logger,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    public function __invoke(ProcessAdRawDocumentMessage $message): void
    {
        try {
            ($this->action)($message->companyId, $message->adRawDocumentId);
            $this->entityManager->flush();
        } catch (AdRawDocumentAlreadyProcessedException $e) {
            // Гонка: документ уже не в DRAFT либо удалён. Ретрай Messenger'а
            // здесь только шумит в failed-queue. Финализацию job'а тоже не
            // запускаем — документ уже не наша забота, его исход обработает
            // тот воркер, который реально перевёл его в терминальный статус.
            $this->marketplaceAdsLogger->info(
                'AdRawDocument обработан параллельно или удалён, повтор не нужен',
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                    'reason' => $e->getMessage(),
                ],
            );

            return;
        } catch (\Throwable $e) {
            // markFailedWithReason — raw DBAL минуя UoW: переживает закрытый
            // после wrapInTransaction EntityManager (Connection остаётся живой).
            $this->adRawDocumentRepository->markFailedWithReason(
                $message->adRawDocumentId,
                $message->companyId,
                $e::class.': '.$e->getMessage(),
            );

            // tryFinalizeJobForDocument использует ORM-запросы (findByIdAndCompany,
            // findActiveJobCoveringDate). Если исходное исключение пришло из
            // EntityManager::wrapInTransaction, Doctrine закрыл EM — secondary
            // "EntityManager is closed" замаскировал бы $e и отправил бы в
            // failed-queue бесполезное сообщение. Глотаем secondary и логируем:
            // следующий успешный message по документу этого job'а всё равно
            // добьёт финализацию.
            try {
                $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);
            } catch (\Throwable $secondary) {
                $this->logger->error(
                    'Финализация job пропущена после ошибки обработки документа',
                    $secondary,
                    [
                        'companyId' => $message->companyId,
                        'adRawDocumentId' => $message->adRawDocumentId,
                        'originalException' => $e::class.': '.$e->getMessage(),
                    ],
                );
            }

            throw $e;
        }

        $document = $this->adRawDocumentRepository->findByIdAndCompany(
            $message->adRawDocumentId,
            $message->companyId,
        );

        if (null === $document) {
            $this->marketplaceAdsLogger->warning('AdRawDocument исчез после успешной обработки', [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
            ]);

            return;
        }

        // Action завершился без исключения, но оставил документ в DRAFT —
        // это «частичный успех» (часть SKU не сматчилась). Без явного перевода
        // в FAILED job никогда не финализируется: COUNT processed+failed
        // останется меньше total.
        if (AdRawDocumentStatus::DRAFT === $document->getStatus()) {
            $this->adRawDocumentRepository->markFailedWithReason(
                $message->adRawDocumentId,
                $message->companyId,
                'Action left document in DRAFT (partial processing failure)',
            );
            $this->marketplaceAdsLogger->warning(
                'AdRawDocument остался в DRAFT после Action — помечен FAILED',
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                ],
            );
            $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);

            return;
        }

        $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);
    }

    /**
     * Находит активный job, покрывающий дату документа, и делегирует финализацию.
     *
     * Документ перечитывается заново — статус мог измениться внутри __invoke
     * (DRAFT → FAILED). Если job не найден (например, уже завершён другим воркером
     * или дата вне всех активных диапазонов) — тихий no-op.
     */
    private function tryFinalizeJobForDocument(string $companyId, string $documentId): void
    {
        $document = $this->adRawDocumentRepository->findByIdAndCompany($documentId, $companyId);
        if (null === $document) {
            return;
        }

        $job = $this->adLoadJobRepository->findActiveJobCoveringDate(
            $companyId,
            $document->getMarketplace(),
            $document->getReportDate(),
        );
        if (null === $job) {
            return;
        }

        $this->adLoadJobFinalizer->tryFinalize($job->getId(), $companyId);
    }
}
