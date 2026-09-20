<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\CatalogPageParser;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladProduct;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Entity\MoySkladVariant;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\CatalogSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladProductRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final readonly class SyncCatalogAction
{
    private const LIMIT = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private MoySkladConnectionWriteRepository $connections,
        private MoySkladProductRepository $products,
        private MoySkladVariantRepository $variants,
        private MoySkladSyncCursorRepository $cursors,
        private MoySkladSyncRunRepository $runs,
        private MoySkladClient $client,
        private CatalogPageParser $parser,
        private ConnectionTokenCodec $codec,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId): bool
    {
        $connection = $this->connections->findByIdAndCompanyId($connectionId, $companyId);
        if (null === $connection || !$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
            return false;
        }

        $db = $this->em->getConnection();
        $lockParams = ['namespace' => 'moysklad-catalog', 'key' => $connectionId];
        $locked = $db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))', $lockParams);
        if (!in_array($locked, [true, 1, '1', 't'], true)) {
            return false;
        }

        try {
            $this->em->refresh($connection);
            if (!$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
                return false;
            }
            $accountId = $connection->getAccountId();
            $this->syncType($companyId, $connectionId, $accountId, $connection, 'product');
            $this->syncType($companyId, $connectionId, $accountId, $connection, 'variant');

            return true;
        } finally {
            try {
                $db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $lockParams);
            } catch (\Throwable) {
                // A lost database session has already released its session lock.
            }
        }
    }

    private function syncType(string $companyId, string $connectionId, string $accountId, MoySkladConnection $connection, string $entityType): void
    {
        $db = $this->em->getConnection();
        $runId = Uuid::uuid7()->toString();
        $db->transactional(function () use ($companyId, $connectionId, $entityType, $runId): void {
            $stale = $this->runs->runningFor($companyId, $connectionId, $entityType);
            if (null !== $stale) {
                $stale->fail('internal', $this->now());
                $this->em->flush();
            }
            $this->em->persist(new MoySkladSyncRun($runId, $companyId, $connectionId, $entityType, $this->now()));
            $this->em->flush();
        });

        try {
            $token = $this->codec->accessTokenFor($connection) ?? '';
            foreach ([false, true] as $archived) {
                $offset = 0;
                $expectedSize = null;
                $lastExternalId = null;
                do {
                    $page = $this->client->fetchCatalogPage($token, $entityType, $archived, $offset, self::LIMIT);
                    $size = $page['meta']['size'];
                    if (null === $expectedSize) {
                        $expectedSize = $size;
                    }
                    if ($size !== $expectedSize) {
                        throw new CatalogSyncException('temporary');
                    }
                    if ('product' === $entityType) {
                        $snapshots = $this->parser->parseProducts($page, $accountId, $archived);
                        $count = count($snapshots);
                        $lastExternalId = $this->assertPage($snapshots, $offset, $expectedSize, $lastExternalId);
                        $this->saveProducts($companyId, $connectionId, $runId, $snapshots);
                    } else {
                        $snapshots = $this->parser->parseVariants($page, $accountId, $archived);
                        $count = count($snapshots);
                        $lastExternalId = $this->assertPage($snapshots, $offset, $expectedSize, $lastExternalId);
                        $this->saveVariants($companyId, $connectionId, $runId, $snapshots);
                    }
                    $offset += $count;
                } while ($offset < $expectedSize);
            }

            $db->transactional(function () use ($companyId, $connectionId, $entityType, $runId): void {
                $completedAt = $this->now();
                $cursor = $this->cursors->findFor($companyId, $connectionId, $entityType);
                if (null === $cursor) {
                    $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $companyId, $connectionId, $entityType);
                    $this->em->persist($cursor);
                }
                $cursor->completeAt($completedAt);
                $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                if (null === $run) {
                    throw new \LogicException('Sync run disappeared.');
                }
                $run->succeed($completedAt);
                $this->em->flush();
            });
            $this->logger->info('MoySklad catalog sync completed', ['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType, 'runId' => $runId]);
        } catch (\Throwable $error) {
            $category = $error instanceof CatalogSyncException ? $error->category : 'internal';
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
                $this->logger->error('MoySklad catalog sync failure status could not be stored', ['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType, 'runId' => $runId]);
            }
            $this->em->clear();
            $this->logger->log(in_array($category, ['rate_limited', 'temporary'], true) ? 'warning' : 'error', 'MoySklad catalog sync failed', ['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType, 'runId' => $runId, 'category' => $category, 'exceptionClass' => $error::class]);
            throw new CatalogSyncException($category, $error instanceof CatalogSyncException ? $error->retryAfterMs : null, $runId);
        }
    }

    /** @param list<\App\MoySklad\Domain\ProductSnapshot|\App\MoySklad\Domain\VariantSnapshot> $snapshots */
    private function assertPage(array $snapshots, int $offset, int $expectedSize, ?string $lastExternalId): ?string
    {
        $count = count($snapshots);
        if ($count > $expectedSize - $offset || (0 === $count && $offset < $expectedSize)) {
            throw new CatalogSyncException('invalid_response');
        }
        foreach ($snapshots as $snapshot) {
            if (null !== $lastExternalId && strcmp($snapshot->externalId, $lastExternalId) <= 0) {
                throw new CatalogSyncException('temporary');
            }
            $lastExternalId = $snapshot->externalId;
        }

        return $lastExternalId;
    }

    /** @param list<\App\MoySklad\Domain\ProductSnapshot> $snapshots */
    private function saveProducts(string $companyId, string $connectionId, string $runId, array $snapshots): void
    {
        $this->em->getConnection()->transactional(function () use ($companyId, $connectionId, $runId, $snapshots): void {
            $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
            if (null === $run) {
                throw new \LogicException('Sync run disappeared.');
            }
            $existing = $this->products->findByExternalIds($companyId, $connectionId, array_map(static fn ($snapshot): string => $snapshot->externalId, $snapshots));
            $created = $updated = $unchanged = 0;
            $loadedAt = $this->now();
            foreach ($snapshots as $snapshot) {
                $record = $existing[$snapshot->externalId] ?? null;
                if (null === $record) {
                    $this->em->persist(new MoySkladProduct(Uuid::uuid7()->toString(), $companyId, $connectionId, $snapshot, $loadedAt));
                    ++$created;
                } elseif ($record->applySnapshot($snapshot, $loadedAt)) {
                    ++$updated;
                } else {
                    ++$unchanged;
                }
            }
            $run->recordPage(count($snapshots), $created, $updated, $unchanged);
            $this->em->flush();
        });
        $this->em->clear();
    }

    /** @param list<\App\MoySklad\Domain\VariantSnapshot> $snapshots */
    private function saveVariants(string $companyId, string $connectionId, string $runId, array $snapshots): void
    {
        $this->em->getConnection()->transactional(function () use ($companyId, $connectionId, $runId, $snapshots): void {
            $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
            if (null === $run) {
                throw new \LogicException('Sync run disappeared.');
            }
            $parentIds = array_values(array_unique(array_map(static fn ($snapshot): string => $snapshot->productExternalId, $snapshots)));
            $parents = $this->products->findByExternalIds($companyId, $connectionId, $parentIds);
            if (count($parents) !== count($parentIds)) {
                throw new CatalogSyncException('temporary');
            }
            $existing = $this->variants->findByExternalIds($companyId, $connectionId, array_map(static fn ($snapshot): string => $snapshot->externalId, $snapshots));
            $created = $updated = $unchanged = 0;
            $loadedAt = $this->now();
            foreach ($snapshots as $snapshot) {
                $record = $existing[$snapshot->externalId] ?? null;
                if (null === $record) {
                    $this->em->persist(new MoySkladVariant(Uuid::uuid7()->toString(), $companyId, $connectionId, $snapshot, $loadedAt));
                    ++$created;
                } elseif ($record->applySnapshot($snapshot, $loadedAt)) {
                    ++$updated;
                } else {
                    ++$unchanged;
                }
            }
            $run->recordPage(count($snapshots), $created, $updated, $unchanged);
            $this->em->flush();
        });
        $this->em->clear();
    }

    private function now(): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $timezone);

        return new \DateTimeImmutable($now->format('Y-m-d H:i:s.v'), $timezone);
    }
}
