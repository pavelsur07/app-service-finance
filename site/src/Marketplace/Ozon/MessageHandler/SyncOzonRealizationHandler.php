<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Infrastructure\Api\OzonRealizationFetcher;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Загрузка отчёта «Реализация» Ozon за месяц и ведение статуса пары «компания × месяц».
 *
 * Исход попытки фиксируется в `marketplace_financial_report_sync_statuses`, а не пробрасывается в Messenger:
 * повторы ведёт часовой опрос (`app:marketplace:ozon-realization-poll`), и два механизма повторов дали бы дубли запросов.
 * - пустой ответ или 4xx (отчёт ещё не сформирован) → `empty` + повтор через час;
 * - 429 → `failed` с `nextRetryAt` из Retry-After; 5xx/сеть → `failed` через 30 минут;
 * - 401/403 → `auth_failed` (терминально, чинится ключом);
 * - строки получены → документ сохранён (перезаписывается при повторной загрузке), `raw_loaded` + хеш строк.
 */
#[AsMessageHandler]
final class SyncOzonRealizationHandler
{
    private const LOCK_TTL_SECONDS = 300;
    private const DOCUMENT_TYPE = 'realization';
    private const NOT_READY_RETRY = 'PT1H';
    private const TEMPORARY_RETRY = 'PT30M';
    private const DEFAULT_RATE_LIMIT_RETRY_SECONDS = 900;
    private const MIN_RATE_LIMIT_RETRY_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonRealizationFetcher $fetcher,
        private readonly MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncOzonRealizationMessage $message): void
    {
        $lockKey = sprintf(
            'ozon_realization_%s_%d_%02d',
            $message->companyId,
            $message->year,
            $message->month,
        );

        $lock = $this->lockFactory->createLock($lockKey, self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon realization sync already in progress, skipping', [
                'company_id' => $message->companyId,
                'year' => $message->year,
                'month' => $message->month,
            ]);

            return;
        }

        try {
            $this->process($message);
        } finally {
            $lock->release();
        }
    }

    private function process(SyncOzonRealizationMessage $message): void
    {
        $company = $this->em->find(Company::class, $message->companyId);
        if (!$company) {
            $this->logger->error('Company not found for Ozon realization sync', [
                'company_id' => $message->companyId,
            ]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $message->connectionId);
        if (!$connection) {
            $this->logger->error('MarketplaceConnection not found', [
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('Connection is inactive, skipping', [
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        $year = $message->year;
        $month = $message->month;

        $status = $this->statusRepository->findOrCreateForDay(
            $message->connectionId,
            $message->companyId,
            MarketplaceType::OZON,
            OzonRealizationReport::REPORT_TYPE,
            OzonRealizationReport::apiEndpoint(),
            OzonRealizationReport::businessDate($year, $month),
        );
        $status->markLoading($status->getMode() ?? FinancialReportSyncMode::MANUAL);
        $this->statusRepository->save($status);
        $this->em->flush();

        $context = [
            'company_id' => $message->companyId,
            'year' => $year,
            'month' => $month,
            'attempt' => $status->getAttempts(),
        ];
        $this->logger->info('Ozon realization sync started', $context);

        try {
            $rawData = $this->fetcher->fetch($connection, $year, $month);
            $rows = $rawData['result']['rows'] ?? [];

            if (!is_array($rows) || [] === $rows) {
                $this->deferNotReady($status, $context, 'empty response');

                return;
            }

            $rawDocId = $this->storeDocument($company, $rawData, $rows, $year, $month);
            $status->markRawLoaded($rawDocId, count($rows), hash('sha256', json_encode($rows, \JSON_UNESCAPED_UNICODE) ?: ''));
            $this->statusRepository->save($status);
            $this->em->flush();

            $this->logger->info('Ozon realization raw document stored', $context + ['raw_doc_id' => $rawDocId, 'rows_count' => count($rows)]);
        } catch (MarketplaceAuthException $e) {
            $status->markAuthFailed($e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $this->saveAndFlush($status);
            $this->logger->warning('Ozon realization sync: API key rejected', $context + ['http_status' => $e->getStatusCode()]);
        } catch (MarketplaceRateLimitException $e) {
            $seconds = max(self::MIN_RATE_LIMIT_RETRY_SECONDS, $e->getRetryAfter() ?? self::DEFAULT_RATE_LIMIT_RETRY_SECONDS);
            $status->markFailedRetryable($e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt(), $this->clock->now()->modify(sprintf('+%d seconds', $seconds)));
            $this->saveAndFlush($status);
            $this->logger->warning('Ozon realization sync: rate limited, will retry', $context + ['retry_in_seconds' => $seconds]);
        } catch (MarketplaceBadRequestException $e) {
            // Отчёт за месяц ещё не сформирован: Ozon отвечает ошибкой клиента. Это ожидание, а не сбой.
            $this->deferNotReady($status, $context, sprintf('HTTP %d', $e->getStatusCode()));
        } catch (MarketplaceTemporaryApiException|TransportExceptionInterface $e) {
            $this->markRetryable($status, $e, $this->clock->now()->add(new \DateInterval(self::TEMPORARY_RETRY)));
            $this->logger->warning('Ozon realization sync: temporary failure, will retry', $context + ['error_class' => $e::class]);
        } catch (\Throwable $e) {
            // Неожиданное (в т.ч. баг кода): повтор ведёт опрос, а инцидент виден сразу и не теряется до конца окна.
            $this->markRetryable($status, $e, $this->clock->now()->add(new \DateInterval(self::NOT_READY_RETRY)));
            $this->logger->error('Ozon realization sync failed', $context + ['error_class' => $e::class, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function deferNotReady(MarketplaceFinancialReportSyncStatus $status, array $context, string $reason): void
    {
        $status->markEmpty();
        $status->scheduleNextRetryAt($this->clock->now()->add(new \DateInterval(self::NOT_READY_RETRY)));
        $this->saveAndFlush($status);

        $this->logger->info('Ozon realization is not ready yet, will retry', $context + ['reason' => $reason]);
    }

    private function markRetryable(MarketplaceFinancialReportSyncStatus $status, \Throwable $e, \DateTimeImmutable $nextRetryAt): void
    {
        $statusCode = $e instanceof MarketplaceApiException ? $e->getStatusCode() : null;
        $excerpt = $e instanceof MarketplaceApiException ? $e->getResponseExcerpt() : null;

        $status->markFailedRetryable($e::class, mb_substr($e->getMessage(), 0, 500), $statusCode, $excerpt, $nextRetryAt);
        $this->saveAndFlush($status);
    }

    private function saveAndFlush(MarketplaceFinancialReportSyncStatus $status): void
    {
        $this->statusRepository->save($status);
        $this->em->flush();
    }

    /**
     * Сохраняет документ отчёта за месяц; повторная загрузка перезаписывает прежний (данные могли измениться).
     *
     * @param array<string, mixed> $rawData
     * @param array<mixed> $rows
     */
    private function storeDocument(Company $company, array $rawData, array $rows, int $year, int $month): string
    {
        $periodFrom = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $periodTo = $periodFrom->modify('last day of this month');

        $existing = $this->em->getRepository(MarketplaceRawDocument::class)->findOneBy([
            'company' => $company,
            'marketplace' => MarketplaceType::OZON,
            'documentType' => self::DOCUMENT_TYPE,
            'periodFrom' => $periodFrom,
        ]);

        if ($existing instanceof MarketplaceRawDocument) {
            $existing->setRawData($rawData);
            $existing->setRecordsCount(count($rows));
            $existing->setSyncNotes(null);
            $this->em->flush();

            return (string) $existing->getId();
        }

        $rawDoc = new MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            self::DOCUMENT_TYPE,
        );
        $rawDoc->setPeriodFrom($periodFrom);
        $rawDoc->setPeriodTo($periodTo);
        $rawDoc->setApiEndpoint(OzonRealizationReport::apiEndpoint());
        $rawDoc->setRawData($rawData);
        $rawDoc->setRecordsCount(count($rows));

        $this->em->persist($rawDoc);
        $this->em->flush();

        return (string) $rawDoc->getId();
    }
}
