<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class WbInitialSyncStartDateResolver
{
    private const DISCOVERY_VERSION = '1';

    public function __construct(
        private WildberriesAdapter $wildberriesAdapter,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(Company $company, MarketplaceConnection $connection): ?\DateTimeImmutable
    {
        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $settings = $connection->getSettings() ?? [];
        $cached = $this->parseCachedStartDate($settings, $yesterday);

        if (null !== $cached) {
            return $cached;
        }

        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y') . '-01-01 00:00:00');
        $startDate = $this->discoverStartDate($company, $yearStart, $yesterday);

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

    private function parseCachedStartDate(array $settings, \DateTimeImmutable $yesterday): ?\DateTimeImmutable
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

        if ($date > $yesterday) {
            $this->logger->warning('WB initial sync start date in settings is in future; discovery will be repeated.', [
                'wb_initial_sync_start_date' => $raw,
                'yesterday' => $yesterday->format('Y-m-d'),
            ]);

            return null;
        }

        return $date;
    }

    private function discoverStartDate(Company $company, \DateTimeImmutable $yearStart, \DateTimeImmutable $yesterday): ?\DateTimeImmutable
    {
        $monthStart = $yearStart;
        while ($monthStart <= $yesterday) {
            $monthEnd = $monthStart->modify('last day of this month')->setTime(0, 0, 0);
            if ($monthEnd > $yesterday) {
                $monthEnd = $yesterday;
            }

            if ($this->wildberriesAdapter->hasRawReportData($company, $monthStart, $monthEnd)) {
                return $monthStart;
            }

            $monthStart = $monthStart->modify('first day of next month')->setTime(0, 0, 0);
        }

        return null;
    }
}

