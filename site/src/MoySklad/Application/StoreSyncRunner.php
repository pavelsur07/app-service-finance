<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Entity\MoySkladStore;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladStoreRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class StoreSyncRunner
{
    private const ENTITY_TYPE = 'store';
    private const LIMIT = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private MoySkladStoreRepository $stores,
        private MoySkladSyncCursorRepository $cursors,
        private MoySkladSyncRunRepository $runs,
        private MoySkladClient $client,
        private StorePageParser $parser,
    ) {
    }

    /** @return list<string> External IDs from the completed active-store pass. */
    public function run(string $companyId, string $connectionId, string $accountId, #[\SensitiveParameter] string $token): array
    {
        $db = $this->em->getConnection();
        $runId = Uuid::uuid7()->toString();
        $db->transactional(function () use ($companyId, $connectionId, $runId): void {
            $stale = $this->runs->runningFor($companyId, $connectionId, self::ENTITY_TYPE);
            if (null !== $stale) {
                $stale->fail('internal', $this->now());
                $this->em->flush();
            }
            $this->em->persist(new MoySkladSyncRun($runId, $companyId, $connectionId, self::ENTITY_TYPE, $this->now()));
            $this->em->flush();
        });

        $activeStoreIds = [];
        try {
            foreach ([false, true] as $archived) {
                $offset = 0;
                $expectedSize = null;
                $seenInPass = [];
                do {
                    $page = $this->client->fetchStorePage($token, $archived, $offset, self::LIMIT);
                    $parsed = $this->parser->parse($page, $accountId, $archived);
                    if (self::LIMIT !== $parsed->limit || $parsed->offset !== $offset) {
                        throw new StockSyncException('invalid_response');
                    }
                    if (null === $expectedSize) {
                        $expectedSize = $parsed->size;
                    } elseif ($parsed->size !== $expectedSize) {
                        throw new StockSyncException('temporary');
                    }
                    foreach ($parsed->rows as $snapshot) {
                        if (isset($seenInPass[$snapshot->externalId])) {
                            throw new StockSyncException('temporary');
                        }
                        $seenInPass[$snapshot->externalId] = true;
                        if (!$archived) {
                            $activeStoreIds[] = $snapshot->externalId;
                        }
                    }

                    $db->transactional(function () use ($companyId, $connectionId, $runId, $parsed): void {
                        $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                        if (null === $run) {
                            throw new \LogicException('Store sync run disappeared.');
                        }
                        $externalIds = array_map(static fn ($snapshot): string => $snapshot->externalId, $parsed->rows);
                        $existing = $this->stores->findByExternalIds($companyId, $connectionId, $externalIds);
                        $created = $updated = $unchanged = 0;
                        $loadedAt = $this->now();
                        foreach ($parsed->rows as $snapshot) {
                            $store = $existing[$snapshot->externalId] ?? null;
                            if (null === $store) {
                                $this->em->persist(new MoySkladStore(Uuid::uuid7()->toString(), $companyId, $connectionId, $snapshot, $loadedAt));
                                ++$created;
                            } elseif ($store->applySnapshot($snapshot, $loadedAt)) {
                                ++$updated;
                            } else {
                                ++$unchanged;
                            }
                        }
                        $run->recordPage(count($parsed->rows), $created, $updated, $unchanged);
                        $this->em->flush();
                    });
                    $this->em->clear();
                    $offset += count($parsed->rows);
                } while ($offset < $expectedSize);
                if (count($seenInPass) !== $expectedSize) {
                    throw new StockSyncException('temporary');
                }
            }

            $db->transactional(function () use ($companyId, $connectionId, $runId): void {
                $completedAt = $this->now();
                $cursor = $this->cursors->findFor($companyId, $connectionId, self::ENTITY_TYPE);
                if (null === $cursor) {
                    $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $companyId, $connectionId, self::ENTITY_TYPE);
                    $this->em->persist($cursor);
                }
                $cursor->completeAt($completedAt);
                $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                if (null === $run) {
                    throw new \LogicException('Store sync run disappeared.');
                }
                $run->succeed($completedAt);
                $this->em->flush();
            });

            return $activeStoreIds;
        } catch (\Throwable $error) {
            $category = $error instanceof StockSyncException ? $error->category : 'internal';
            if (!in_array($category, MoySkladSyncRun::ERROR_CATEGORIES, true)) {
                $category = 'internal';
            }
            try {
                $db->executeStatement("UPDATE moysklad_sync_runs SET status = 'failed', error_category = :category, finished_at = :finishedAt WHERE id = :runId AND company_id = :companyId AND status = 'running'", [
                    'category' => $category,
                    'finishedAt' => $this->now()->format('Y-m-d H:i:s.v'),
                    'runId' => $runId,
                    'companyId' => $companyId,
                ]);
            } catch (\Throwable) {
                // Preserve the original retry category if the database session is lost.
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
