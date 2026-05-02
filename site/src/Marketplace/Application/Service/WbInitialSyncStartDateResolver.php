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
    private const DEFAULT_INITIAL_DAYS = 60;

    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function resolve(Company $company, MarketplaceConnection $connection): \DateTimeImmutable
    {
        $now = $this->clock->now()->setTime(0, 0, 0);
        $yearStart = new \DateTimeImmutable((int) $now->format('Y') . '-01-01 00:00:00');
        $yesterday = $now->modify('-1 day');
        $settingsOverride = $this->parseSettingsStartDateOverride($connection->getSettings() ?? [], $yesterday);

        if (null !== $settingsOverride) {
            return $settingsOverride;
        }

        $localStartDate = $this->rawDocumentRepository->findMinPeriodFromForSuccessfulDocuments(
            company: $company,
            marketplace: $connection->getMarketplace(),
            documentType: self::DOCUMENT_TYPE,
            apiEndpoint: 'wildberries::reportDetailByPeriod',
            yearStart: $yearStart,
            yesterday: $yesterday,
        );

        if (null !== $localStartDate) {
            return $localStartDate->setTime(0, 0, 0);
        }

        return $now->modify(sprintf('-%d days', self::DEFAULT_INITIAL_DAYS));
    }

    private function parseSettingsStartDateOverride(array $settings, \DateTimeImmutable $maxAllowedDate): ?\DateTimeImmutable
    {
        $raw = $settings['wb_initial_sync_start_date'] ?? null;
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date) {
            return null;
        }

        if (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) {
            return null;
        }

        if ($date > $maxAllowedDate) {
            return null;
        }

        return $date;
    }
}
