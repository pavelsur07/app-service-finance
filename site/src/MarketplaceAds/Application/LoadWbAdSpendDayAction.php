<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Application\DTO\WbAdSpendLoadResult;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdClient;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdRawDataParser;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
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
        $result = $this->result($rawDocument, $entries);
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
            'actualTotal' => $result->actualTotal,
            'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * @param list<AdRawEntry> $entries
     */
    private function result(AdRawDocument $rawDocument, array $entries): WbAdSpendLoadResult
    {
        $campaigns = [];
        $skuCount = 0;
        $attributed = '0.00';
        $unallocated = '0.00';

        foreach ($entries as $entry) {
            $campaigns['id:'.$entry->campaignId] = true;
            if (AdRawEntry::UNALLOCATED_PARENT_SKU === $entry->parentSku) {
                $unallocated = bcadd($unallocated, $entry->cost, 2);
            } else {
                ++$skuCount;
                $attributed = bcadd($attributed, $entry->cost, 2);
            }
        }

        return new WbAdSpendLoadResult(
            rawDocumentId: $rawDocument->getId(),
            status: $rawDocument->getStatus(),
            campaignCount: count($campaigns),
            skuCount: $skuCount,
            attributedTotal: $attributed,
            unallocatedTotal: $unallocated,
            actualTotal: bcadd($attributed, $unallocated, 2),
        );
    }
}
