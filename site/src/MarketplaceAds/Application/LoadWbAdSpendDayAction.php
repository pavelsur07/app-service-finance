<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Application\DTO\WbAdSpendLoadResult;
use App\MarketplaceAds\Application\DTO\WbAdSpendReconciliation;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\WbAdSpendReconciliationException;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdClient;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdRawDataParser;
use App\MarketplaceAds\Infrastructure\Query\WbAdSpendReconciliationQuery;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

final readonly class LoadWbAdSpendDayAction implements LoadWbAdSpendDayActionInterface
{
    public function __construct(
        private WildberriesAdClient $client,
        private WildberriesAdRawDataParser $parser,
        private AdRawDocumentRepository $rawDocumentRepository,
        private ProcessAdRawDocumentAction $processAction,
        private WbAdSpendReconciliationQuery $reconciliationQuery,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $connectionId,
        \DateTimeImmutable $date,
    ): WbAdSpendLoadResult {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $date = $date->setTime(0, 0);
        $dateString = $date->format('Y-m-d');
        $startedAt = microtime(true);

        $this->logger->info('WB daily ad spend load started.', [
            'companyId' => $companyId,
            'connectionId' => $connectionId,
            'date' => $dateString,
        ]);

        $payload = $this->client->fetchAdStatisticsForConnection(
            $companyId,
            $connectionId,
            $date,
        );
        $sourceKey = sprintf('wb-ad-spend:%s:%s', $connectionId, $dateString);
        $rawDocument = $this->rawDocumentRepository->findBySourceKey(
            $companyId,
            MarketplaceType::WILDBERRIES->value,
            $sourceKey,
        );

        if (null === $rawDocument) {
            $rawDocument = new AdRawDocument(
                companyId: $companyId,
                marketplace: MarketplaceType::WILDBERRIES,
                reportDate: $date,
                rawPayload: $payload,
                sourceKey: $sourceKey,
            );
            $this->rawDocumentRepository->save($rawDocument);
        } else {
            $rawDocument->updatePayload($payload);
        }

        // Persist the raw response before projection. If projection fails, the
        // exact response remains available in DRAFT for a safe rerun.
        $this->entityManager->flush();
        ($this->processAction)($companyId, $rawDocument->getId());

        // ProcessAdRawDocumentAction has already validated the payload. Parse it
        // once more only to build the operational summary returned to the
        // command; persistence remains the source of truth.
        $entries = $this->parser->parse($payload);
        $reconciliation = $this->reconciliationQuery->get($companyId, $rawDocument->getId());
        $result = $this->result($rawDocument, $entries, $reconciliation);
        if (!$result->reconciled) {
            $this->logger->error('WB daily ad spend reconciliation failed.', [
                'event' => 'wb_ad_spend_reconciliation_failed',
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'date' => $dateString,
                'rawDocumentId' => $result->rawDocumentId,
                'sourceTotal' => $result->actualTotal,
                'documentTotal' => $result->documentTotal,
                'lineTotal' => $result->lineTotal,
                'withoutLineTotal' => $result->withoutLineTotal,
                'sourceUnallocatedTotal' => $result->unallocatedTotal,
                'persistedUnallocatedTotal' => $result->persistedUnallocatedTotal,
                'unmappedTotal' => $result->unmappedTotal,
                'unmappedCount' => $result->unmappedCount,
            ]);
            if (AdRawDocumentStatus::DRAFT !== $rawDocument->getStatus()) {
                $rawDocument->resetToDraft();
                $this->entityManager->flush();
            }

            throw new WbAdSpendReconciliationException(sprintf('WB ad spend reconciliation failed for raw document %s.', $result->rawDocumentId));
        }
        $this->logger->info('WB daily ad spend load finished.', [
            'companyId' => $companyId,
            'connectionId' => $connectionId,
            'date' => $dateString,
            'rawDocumentId' => $result->rawDocumentId,
            'status' => $result->status->value,
            'campaignCount' => $result->campaignCount,
            'skuCount' => $result->skuCount,
            'attributedTotal' => $result->attributedTotal,
            'unallocatedTotal' => $result->unallocatedTotal,
            'persistedUnallocatedTotal' => $result->persistedUnallocatedTotal,
            'actualTotal' => $result->actualTotal,
            'documentTotal' => $result->documentTotal,
            'lineTotal' => $result->lineTotal,
            'withoutLineTotal' => $result->withoutLineTotal,
            'unmappedTotal' => $result->unmappedTotal,
            'unmappedCount' => $result->unmappedCount,
            'reconciled' => $result->reconciled,
            'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * @param list<AdRawEntry> $entries
     */
    private function result(
        AdRawDocument $rawDocument,
        array $entries,
        WbAdSpendReconciliation $reconciliation,
    ): WbAdSpendLoadResult {
        $campaigns = [];
        $skuCount = 0;
        $attributed = Money::fromMinor(0, 'RUB');
        $unallocated = Money::fromMinor(0, 'RUB');

        foreach ($entries as $entry) {
            $campaigns['id:'.$entry->campaignId] = true;
            $cost = Money::fromString($entry->cost, 'RUB');
            if (AdRawEntry::UNALLOCATED_PARENT_SKU === $entry->parentSku) {
                $unallocated = $unallocated->add($cost);
            } else {
                ++$skuCount;
                $attributed = $attributed->add($cost);
            }
        }

        $actual = $attributed->add($unallocated);

        return new WbAdSpendLoadResult(
            rawDocumentId: $rawDocument->getId(),
            status: $rawDocument->getStatus(),
            campaignCount: count($campaigns),
            skuCount: $skuCount,
            attributedTotal: $attributed->toDecimalString(),
            unallocatedTotal: $unallocated->toDecimalString(),
            persistedUnallocatedTotal: $reconciliation->unallocatedTotal->toDecimalString(),
            actualTotal: $actual->toDecimalString(),
            documentTotal: $reconciliation->documentTotal->toDecimalString(),
            lineTotal: $reconciliation->lineTotal->toDecimalString(),
            withoutLineTotal: $reconciliation->withoutLineTotal->toDecimalString(),
            unmappedTotal: $reconciliation->unmappedTotal->toDecimalString(),
            unmappedCount: $reconciliation->unmappedCount,
            reconciled: $reconciliation->reconciles($actual, $unallocated),
        );
    }
}
