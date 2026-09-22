<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladProductRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotLineRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStoreRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class StockSnapshotRunner
{
    private const ENTITY_TYPE = 'stock';
    private const LIMIT = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private MoySkladStoreRepository $stores,
        private MoySkladProductRepository $products,
        private MoySkladVariantRepository $variants,
        private MoySkladStockSnapshotRepository $snapshots,
        private MoySkladStockSnapshotLineRepository $lines,
        private MoySkladSyncCursorRepository $cursors,
        private MoySkladSyncRunRepository $runs,
        private MoySkladClient $client,
        private StockReportPageParser $parser,
    ) {
    }

    /** @param list<string> $activeStoreIds */
    public function run(string $companyId, string $connectionId, array $activeStoreIds, #[\SensitiveParameter] string $token): string
    {
        $db = $this->em->getConnection();
        $expectedStoreIds = array_fill_keys($activeStoreIds, true);
        $runId = Uuid::uuid7()->toString();
        $snapshotId = Uuid::uuid7()->toString();
        $db->transactional(function () use ($companyId, $connectionId, $runId, $snapshotId): void {
            $now = $this->now();
            $staleRun = $this->runs->runningFor($companyId, $connectionId, self::ENTITY_TYPE);
            if (null !== $staleRun) {
                $staleRun->fail('internal', $now);
            }
            $staleSnapshot = $this->snapshots->buildingFor($companyId, $connectionId);
            if (null !== $staleSnapshot) {
                $staleSnapshot->fail($now);
            }
            if (null !== $staleRun || null !== $staleSnapshot) {
                $this->em->flush();
            }
            $this->em->persist(new MoySkladSyncRun($runId, $companyId, $connectionId, self::ENTITY_TYPE, $now));
            $this->em->persist(new MoySkladStockSnapshot($snapshotId, $companyId, $connectionId, $now));
            $this->em->flush();
        });

        $expectedSize = null;
        $offset = 0;
        $writtenLines = 0;
        $seenAssortmentIds = [];
        try {
            do {
                $json = $this->client->fetchStockReportPage($token, $offset, self::LIMIT);
                $page = $this->parser->parse($json);
                if (self::LIMIT !== $page->limit || $page->offset !== $offset) {
                    throw new StockSyncException('invalid_response');
                }
                if (null === $expectedSize) {
                    $expectedSize = $page->size;
                } elseif ($page->size !== $expectedSize) {
                    throw new StockSyncException('temporary');
                }
                foreach ($page->rows as $assortment) {
                    if (isset($seenAssortmentIds[$assortment->assortmentExternalId])) {
                        throw new StockSyncException('temporary');
                    }
                    $seenAssortmentIds[$assortment->assortmentExternalId] = true;
                    $reportedStoreIds = [];
                    foreach ($assortment->levels as $level) {
                        $reportedStoreIds[$level->storeExternalId] = true;
                    }
                    if ([] !== array_diff_key($expectedStoreIds, $reportedStoreIds)) {
                        throw new StockSyncException('temporary');
                    }
                }

                $pageLineCount = $db->transactional(function () use ($companyId, $connectionId, $snapshotId, $runId, $page): int {
                    $storeIds = [];
                    $productIds = [];
                    $variantIds = [];
                    foreach ($page->rows as $assortment) {
                        if ('product' === $assortment->assortmentType) {
                            $productIds[] = $assortment->assortmentExternalId;
                        } else {
                            $variantIds[] = $assortment->assortmentExternalId;
                        }
                        foreach ($assortment->levels as $level) {
                            $storeIds[] = $level->storeExternalId;
                        }
                    }
                    $storeIds = array_values(array_unique($storeIds));
                    $productIds = array_values(array_unique($productIds));
                    $variantIds = array_values(array_unique($variantIds));
                    $stores = $this->stores->findByExternalIds($companyId, $connectionId, $storeIds);
                    $products = $this->products->findByExternalIds($companyId, $connectionId, $productIds);
                    $variants = $this->variants->findByExternalIds($companyId, $connectionId, $variantIds);
                    if (count($stores) !== count($storeIds) || count($products) !== count($productIds) || count($variants) !== count($variantIds)) {
                        throw new StockSyncException('temporary');
                    }

                    $count = 0;
                    foreach ($page->rows as $assortment) {
                        foreach ($assortment->levels as $level) {
                            $this->em->persist(new MoySkladStockSnapshotLine(
                                Uuid::uuid7()->toString(),
                                $companyId,
                                $connectionId,
                                $snapshotId,
                                $level->storeExternalId,
                                $assortment->assortmentType,
                                $assortment->assortmentExternalId,
                                $level->stock,
                                $level->reserve,
                                $level->inTransit,
                            ));
                            ++$count;
                        }
                    }
                    $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                    if (null === $run) {
                        throw new \LogicException('Stock sync run disappeared.');
                    }
                    $run->recordPage($count, $count, 0, 0);
                    $this->em->flush();

                    return $count;
                });
                $writtenLines += $pageLineCount;
                $this->em->clear();
                $offset += count($page->rows);
            } while ($offset < $expectedSize);

            if (count($seenAssortmentIds) !== $expectedSize) {
                throw new StockSyncException('temporary');
            }

            $db->transactional(function () use ($companyId, $connectionId, $snapshotId, $runId, $writtenLines): void {
                $completedAt = $this->now();
                $snapshot = $this->snapshots->findByIdAndCompanyId($snapshotId, $companyId);
                $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                if (null === $snapshot || null === $run || $this->lines->countForSnapshot($companyId, $snapshotId) !== $writtenLines) {
                    throw new \LogicException('Stock snapshot state disappeared.');
                }
                $cursor = $this->cursors->findFor($companyId, $connectionId, self::ENTITY_TYPE);
                if (null === $cursor) {
                    $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $companyId, $connectionId, self::ENTITY_TYPE);
                    $this->em->persist($cursor);
                }
                $cursor->completeAt($completedAt);
                $snapshot->complete($completedAt);
                $run->succeed($completedAt);
                $this->em->flush();
            });

            return $snapshotId;
        } catch (\Throwable $error) {
            $category = $error instanceof StockSyncException ? $error->category : 'internal';
            if (!in_array($category, MoySkladSyncRun::ERROR_CATEGORIES, true)) {
                $category = 'internal';
            }
            try {
                try {
                    $db->transactional(function () use ($companyId, $runId, $snapshotId, $category): void {
                        $finishedAt = $this->now()->format('Y-m-d H:i:s.v');
                        $this->em->getConnection()->executeStatement("UPDATE moysklad_sync_runs SET status = 'failed', error_category = :category, finished_at = :finishedAt WHERE id = :runId AND company_id = :companyId AND status = 'running'", [
                            'category' => $category,
                            'finishedAt' => $finishedAt,
                            'runId' => $runId,
                            'companyId' => $companyId,
                        ]);
                        $this->em->getConnection()->executeStatement("UPDATE moysklad_stock_snapshots SET status = 'failed', completed_at = :finishedAt WHERE id = :snapshotId AND company_id = :companyId AND status = 'building'", [
                            'finishedAt' => $finishedAt,
                            'snapshotId' => $snapshotId,
                            'companyId' => $companyId,
                        ]);
                    });
                } catch (\Throwable) {
                    // Preserve the original retry category if the database session is lost.
                }
            } finally {
                $this->em->clear();
            }

            throw new StockSyncException($category, $error instanceof StockSyncException ? $error->retryAfterMs : null, $runId);
        }
    }

    private function now(): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $timezone);

        return new \DateTimeImmutable($now->format('Y-m-d H:i:s.v'), $timezone);
    }
}
