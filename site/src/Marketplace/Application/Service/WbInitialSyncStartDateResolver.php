<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

class WbInitialSyncStartDateResolver
{
    private const DISCOVERY_VERSION = '1';
    private const DOCUMENT_TYPE = 'sales_report';

    public function __construct(
        private WildberriesAdapter $wildberriesAdapter,
        private MarketplaceRawDocumentRepository $rawDocumentRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(Company $company, MarketplaceConnection $connection): ?\DateTimeImmutable
    {
        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $settings = $connection->getSettings() ?? [];
        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y') . '-01-01 00:00:00');
        $cached = $this->parseCachedStartDate($settings, $yearStart, $yesterday);

        if (null !== $cached) {
            return $cached;
        }

        $localStartDate = $this->rawDocumentRepository->findMinPeriodFromForSuccessfulDocuments(
            company: $company,
            marketplace: $connection->getMarketplace(),
            documentType: self::DOCUMENT_TYPE,
            apiEndpoint: $this->wildberriesAdapter->getApiEndpointName(),
            yearStart: $yearStart,
            yesterday: $yesterday,
        );

        if (null !== $localStartDate) {
            $connection->mergeSettings([
                'wb_initial_sync_start_date' => $localStartDate->format('Y-m-d'),
                'wb_initial_sync_discovery_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'wb_initial_sync_discovery_source' => 'local_raw_documents',
                'wb_initial_sync_discovery_version' => self::DISCOVERY_VERSION,
            ]);
            $this->entityManager->flush();

            return $localStartDate;
        }

        $startDate = $this->discoverStartDate($company, $connection, $settings, $yearStart, $yesterday);

        if (null === $startDate) {
            $connection->mergeSettings([
                'wb_initial_sync_no_data_found_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'wb_initial_sync_discovery_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'wb_initial_sync_discovery_version' => self::DISCOVERY_VERSION,
            ]);
            $this->entityManager->flush();

            return null;
        }

        $connection->mergeSettings([
            'wb_initial_sync_start_date' => $startDate->format('Y-m-d'),
            'wb_initial_sync_discovery_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'wb_initial_sync_discovery_version' => self::DISCOVERY_VERSION,
        ]);
        $this->entityManager->flush();

        return $startDate;
    }

    private function parseCachedStartDate(array $settings, \DateTimeImmutable $yearStart, \DateTimeImmutable $yesterday): ?\DateTimeImmutable
    {
        $raw = $settings['wb_initial_sync_start_date'] ?? null;
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($raw . ' 00:00:00');
        } catch (\Throwable) {
            $this->logger->warning('WB initial sync start date in settings is invalid; discovery will be repeated.', [
                'wb_initial_sync_start_date' => $raw,
            ]);

            return null;
        }

        if ($date > $yesterday || $date < $yearStart) {
            $this->logger->warning('WB initial sync start date in settings is in future; discovery will be repeated.', [
                'wb_initial_sync_start_date' => $raw,
                'year_start' => $yearStart->format('Y-m-d'),
                'yesterday' => $yesterday->format('Y-m-d'),
            ]);

            return null;
        }

        return $date;
    }

    private function discoverStartDate(
        Company $company,
        MarketplaceConnection $connection,
        array $settings,
        \DateTimeImmutable $yearStart,
        \DateTimeImmutable $yesterday,
    ): ?\DateTimeImmutable
    {
        $retryNotBefore = $settings['wb_initial_sync_discovery_retry_not_before'] ?? null;
        if (is_string($retryNotBefore) && '' !== trim($retryNotBefore)) {
            try {
                $notBefore = new \DateTimeImmutable($retryNotBefore);
                if ($notBefore > $this->clock->now()) {
                    $retryAfter = $notBefore->getTimestamp() - $this->clock->now()->getTimestamp();

                    throw new MarketplaceRateLimitException(
                        429,
                        'Discovery paused after previous rate limit.',
                        $yearStart->format('Y-m-d'),
                        $yesterday->format('Y-m-d'),
                        $retryAfter,
                    );
                }
            } catch (MarketplaceRateLimitException $e) {
                throw $e;
            } catch (\Throwable) {
            }
        }

        $monthStart = $yearStart;
        $resumeMonth = $settings['wb_initial_sync_discovery_last_probed_month'] ?? null;
        if (is_string($resumeMonth) && '' !== trim($resumeMonth)) {
            try {
                $resumeDate = new \DateTimeImmutable($resumeMonth . '-01 00:00:00');
                if ($resumeDate >= $yearStart && $resumeDate <= $yesterday) {
                    $monthStart = $resumeDate;
                }
            } catch (\Throwable) {
            }
        }

        while ($monthStart <= $yesterday) {
            $monthEnd = $monthStart->modify('last day of this month')->setTime(0, 0, 0);
            if ($monthEnd > $yesterday) {
                $monthEnd = $yesterday;
            }

            try {
                if ($this->wildberriesAdapter->hasRawReportData($company, $monthStart, $monthEnd)) {
                    return $monthStart;
                }
            } catch (MarketplaceRateLimitException $e) {
                $retryAfter = $e->getRetryAfter();
                if (null !== $retryAfter && $retryAfter >= 300) {
                    $connection->mergeSettings([
                        'wb_initial_sync_discovery_retry_not_before' => $this->clock->now()->modify(sprintf('+%d seconds', $retryAfter))->format(\DateTimeInterface::ATOM),
                        'wb_initial_sync_discovery_version' => self::DISCOVERY_VERSION,
                    ]);
                    $this->entityManager->flush();
                }

                throw $e;
            }

            $connection->mergeSettings([
                'wb_initial_sync_discovery_last_probed_month' => $monthStart->format('Y-m'),
                'wb_initial_sync_discovery_version' => self::DISCOVERY_VERSION,
            ]);
            $this->entityManager->flush();

            $monthStart = $monthStart->modify('first day of next month')->setTime(0, 0, 0);
        }

        return null;
    }
}
