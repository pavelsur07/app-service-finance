<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Domain\Service\AdCostDistributor;
use App\MarketplaceAds\Domain\Service\ListingSalesProviderInterface;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;
use App\MarketplaceAds\Repository\AdDocumentRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Обрабатывает AdRawDocument: парсит payload, маппит SKU на листинги,
 * распределяет рекламные затраты и создаёт AdDocument + AdDocumentLine.
 *
 * Контракт:
 * - идемпотентность: повторный запуск удаляет ранее созданные AdDocument и создаёт заново;
 * - частичный успех: если часть SKU не найдена, остальные записи обрабатываются,
 *   raw-документ остаётся в DRAFT для ручного разбора;
 * - статус PROCESSED ставится только при полной обработке без пропусков;
 * - bulk-fetch листингов и продаж: два запроса на весь документ вместо N+1.
 */
final readonly class ProcessAdRawDocumentAction
{
    /**
     * @param iterable<AdRawDataParserInterface> $parsers
     */
    public function __construct(
        private AdRawDocumentRepository $rawDocumentRepository,
        private AdDocumentRepository $adDocumentRepository,
        private iterable $parsers,
        private ListingSalesProviderInterface $listingSalesProvider,
        private AdCostDistributor $costDistributor,
        private EntityManagerInterface $entityManager,
        private AppLogger $logger,
    ) {}

    public function __invoke(string $companyId, string $adRawDocumentId): void
    {
        $rawDocument = $this->rawDocumentRepository->findByIdAndCompany($adRawDocumentId, $companyId)
            ?? throw new \DomainException(sprintf('AdRawDocument %s не найден.', $adRawDocumentId));

        if ($rawDocument->getStatus() !== AdRawDocumentStatus::DRAFT) {
            throw new \DomainException(sprintf(
                'AdRawDocument %s в статусе %s, ожидался draft.',
                $adRawDocumentId,
                $rawDocument->getStatus()->value,
            ));
        }

        $marketplace = $rawDocument->getMarketplace();
        $parser      = $this->selectParser($marketplace->value)
            ?? throw new \DomainException(sprintf(
                'Парсер для площадки %s не найден.',
                $marketplace->value,
            ));

        $entries    = $parser->parse($rawDocument->getRawPayload());
        $reportDate = $rawDocument->getReportDate();

        // Шаг 1: bulk-prefetch листингов по всем уникальным parentSku из валидных записей.
        // Записи с пустым campaignName конструктор AdDocument отвергнет (Assert::notEmpty);
        // их тоже исключаем из предзагрузки, чтобы не тянуть лишние данные.
        $validEntries = array_values(array_filter(
            $entries,
            static fn(AdRawEntry $e) => $e->campaignName !== '',
        ));

        $parentSkus = array_values(array_unique(array_map(
            static fn(AdRawEntry $e) => $e->parentSku,
            $validEntries,
        )));

        $listingsByParentSku = $parentSkus === []
            ? []
            : $this->listingSalesProvider->findListingsByParentSkus(
                $companyId,
                $marketplace->value,
                $parentSkus,
            );

        // Шаг 2: bulk-prefetch продаж по всем найденным листингам за дату отчёта.
        $allListingIds = [];
        foreach ($listingsByParentSku as $listings) {
            foreach ($listings as $listing) {
                $allListingIds[] = $listing['id'];
            }
        }

        $salesByListing = $allListingIds === []
            ? []
            : $this->listingSalesProvider->getSalesQuantitiesByListings(
                $companyId,
                $allListingIds,
                $reportDate,
            );

        $hasErrors       = false;
        $skippedEntries  = 0;
        $processedCount  = 0;

        $this->entityManager->wrapInTransaction(function () use (
            $rawDocument,
            $companyId,
            $adRawDocumentId,
            $marketplace,
            $reportDate,
            $entries,
            $listingsByParentSku,
            $salesByListing,
            &$hasErrors,
            &$skippedEntries,
            &$processedCount,
        ): void {
            // Идемпотентность: удаляем старые AdDocument и их строки (каскад по FK).
            $this->adDocumentRepository->deleteByRawDocumentId($companyId, $adRawDocumentId);

            foreach ($entries as $entry) {
                // AdDocument требует непустой campaignName (Assert::notEmpty). Если парсер отдал
                // пустое имя (поле отсутствовало в payload), пропускаем запись: без проверки
                // здесь конструктор AdDocument выбросит исключение и откатит всю транзакцию,
                // убив частичный успех для остальных записей.
                if ($entry->campaignName === '') {
                    $hasErrors = true;
                    ++$skippedEntries;
                    $this->logger->warning(
                        'AdRawEntry пропущена: отсутствует campaignName',
                        [
                            'adRawDocumentId' => $adRawDocumentId,
                            'companyId'       => $companyId,
                            'marketplace'     => $marketplace->value,
                            'campaignId'      => $entry->campaignId,
                            'parentSku'       => $entry->parentSku,
                        ],
                    );
                    continue;
                }

                $listings = $listingsByParentSku[$entry->parentSku] ?? [];

                if ($listings === []) {
                    $hasErrors = true;
                    ++$skippedEntries;
                    $this->logger->warning(
                        'AdRawEntry пропущена: листинги по parentSku не найдены',
                        [
                            'adRawDocumentId' => $adRawDocumentId,
                            'companyId'       => $companyId,
                            'marketplace'     => $marketplace->value,
                            'parentSku'       => $entry->parentSku,
                            'campaignId'      => $entry->campaignId,
                        ],
                    );
                    continue;
                }

                $distribution = $this->costDistributor->distribute(
                    listings:         $listings,
                    salesByListing:   $salesByListing,
                    totalCost:        $entry->cost,
                    totalImpressions: $entry->impressions,
                    totalClicks:      $entry->clicks,
                );

                $adDocument = new AdDocument(
                    companyId:        $companyId,
                    marketplace:      $marketplace,
                    reportDate:       $reportDate,
                    campaignId:       $entry->campaignId,
                    campaignName:     $entry->campaignName,
                    parentSku:        $entry->parentSku,
                    totalCost:        $entry->cost,
                    totalImpressions: $entry->impressions,
                    totalClicks:      $entry->clicks,
                    adRawDocumentId:  $adRawDocumentId,
                );
                $this->entityManager->persist($adDocument);

                foreach ($distribution as $line) {
                    $this->entityManager->persist(new AdDocumentLine(
                        adDocument:   $adDocument,
                        listingId:    $line->listingId,
                        sharePercent: $line->sharePercent,
                        cost:         $line->cost,
                        impressions:  $line->impressions,
                        clicks:       $line->clicks,
                    ));
                }

                ++$processedCount;
            }

            // wrapInTransaction сам вызовет flush() и commit() после выхода из closure;
            // изменение статуса rawDocument попадёт в тот же flush, т.к. сущность managed.
            if (!$hasErrors) {
                $rawDocument->markAsProcessed();
            }
        });

        if ($hasErrors) {
            $this->logger->info(
                'Частичная обработка AdRawDocument: документ остаётся в DRAFT',
                [
                    'adRawDocumentId' => $adRawDocumentId,
                    'companyId'       => $companyId,
                    'processedCount'  => $processedCount,
                    'skippedCount'    => $skippedEntries,
                ],
            );
        }
    }

    private function selectParser(string $marketplace): ?AdRawDataParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($marketplace)) {
                return $parser;
            }
        }

        return null;
    }
}
