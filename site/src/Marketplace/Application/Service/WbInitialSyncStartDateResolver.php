<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Symfony\Component\Clock\ClockInterface;

class WbInitialSyncStartDateResolver
{
    private const DOCUMENT_TYPE = 'sales_report';

    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function resolve(Company $company, MarketplaceConnection $connection): ?\DateTimeImmutable
    {
        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y') . '-01-01 00:00:00');
        $cached = $this->parseCachedStartDate($connection->getSettings() ?? [], $yearStart, $yesterday);

        if (null !== $cached) {
            return $cached;
        }

        return $this->rawDocumentRepository->findMinPeriodFromForSuccessfulDocuments(
            company: $company,
            marketplace: $connection->getMarketplace(),
            documentType: self::DOCUMENT_TYPE,
            apiEndpoint: 'wildberries::reportDetailByPeriod',
            yearStart: $yearStart,
            yesterday: $yesterday,
        );
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
            return null;
        }

        if ($date > $yesterday || $date < $yearStart) {
            return null;
        }

        return $date;
    }
}
