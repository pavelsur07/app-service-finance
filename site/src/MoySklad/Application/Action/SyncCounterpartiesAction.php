<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\CounterpartyPageParser;
use App\MoySklad\Entity\MoySkladCounterparty;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\CounterpartySyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladCounterpartyRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final readonly class SyncCounterpartiesAction
{
    private const ENTITY_TYPE = 'counterparty';
    private const LIMIT = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private MoySkladConnectionWriteRepository $connections,
        private MoySkladCounterpartyRepository $counterparties,
        private MoySkladSyncCursorRepository $cursors,
        private MoySkladSyncRunRepository $runs,
        private MoySkladClient $client,
        private CounterpartyPageParser $parser,
        private ConnectionTokenCodec $codec,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId): ?MoySkladSyncRun
    {
        $connection = $this->connections->findByIdAndCompanyId($connectionId, $companyId);
        if (null === $connection || !$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
            return null;
        }

        $db = $this->em->getConnection();
        $lockParams = ['namespace' => 'moysklad-counterparty', 'key' => $connectionId];
        $locked = $db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))', $lockParams);
        if (!in_array($locked, [true, 1, '1', 't'], true)) {
            return null;
        }

        $runId = Uuid::uuid7()->toString();
        try {
            $this->em->refresh($connection);
            if (!$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
                return null;
            }
            // A worker crash releases the session lock, but leaves its row running.
            // Repair that row before creating another one under the partial unique index.
            $db->transactional(function () use ($companyId, $connectionId, $runId): void {
                $stale = $this->runs->runningFor($companyId, $connectionId, self::ENTITY_TYPE);
                if (null !== $stale) {
                    $stale->fail('internal', $this->now());
                    $this->em->flush();
                }
                $run = new MoySkladSyncRun($runId, $companyId, $connectionId, self::ENTITY_TYPE, $this->now());
                $this->em->persist($run);
                $this->em->flush();
            });

            try {
                $token = $this->codec->accessTokenFor($connection);
                if (null === $token || '' === $token) {
                    throw new CounterpartySyncException('auth');
                }
                $accountId = $connection->getAccountId();
                foreach ([false, true] as $archived) {
                    $offset = 0;
                    do {
                        $page = $this->client->fetchCounterpartyPage($token, $archived, $offset, self::LIMIT);
                        $snapshots = $this->parser->parse($page, $accountId, $archived);
                        $count = count($snapshots);
                        $db->transactional(function () use ($snapshots, $runId, $companyId, $connectionId): void {
                            $run = $this->runs->findByIdAndCompanyId($runId, $companyId);
                            if (null === $run) {
                                throw new \LogicException('Sync run disappeared.');
                            }
                            $created = $updated = $unchanged = 0;
                            $loadedAt = $this->now();
                            $existing = $this->counterparties->findByExternalIds($companyId, $connectionId, array_map(static fn ($snapshot): string => $snapshot->externalId, $snapshots));
                            foreach ($snapshots as $snapshot) {
                                $record = $existing[$snapshot->externalId] ?? null;
                                if (null === $record) {
                                    $this->em->persist(new MoySkladCounterparty(Uuid::uuid7()->toString(), $companyId, $connectionId, $snapshot, $loadedAt));
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
                        $offset += $count;
                    } while (self::LIMIT === $count);
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
                        throw new \LogicException('Sync run disappeared.');
                    }
                    $run->succeed($completedAt);
                    $this->em->flush();
                });
                $this->logger->info('MoySklad counterparty sync completed', ['companyId' => $companyId, 'connectionId' => $connectionId, 'runId' => $runId]);

                return $this->runs->findByIdAndCompanyId($runId, $companyId);
            } catch (\Throwable $error) {
                $category = $error instanceof CounterpartySyncException ? $error->category : 'internal';
                if (!in_array($category, MoySkladSyncRun::ERROR_CATEGORIES, true)) {
                    $category = 'internal';
                }
                try {
                    // A failed ORM flush closes EntityManager. DBAL remains usable
                    // after transactional rollback, so record the outcome directly.
                    $db->executeStatement("UPDATE moysklad_sync_runs SET status = 'failed', error_category = :category, finished_at = :finishedAt WHERE id = :runId AND company_id = :companyId AND status = 'running'", [
                        'category' => $category,
                        'finishedAt' => $this->now()->format('Y-m-d H:i:s.v'),
                        'runId' => $runId,
                        'companyId' => $companyId,
                    ]);
                } catch (\Throwable) {
                    $this->logger->error('MoySklad counterparty sync failure status could not be stored', ['companyId' => $companyId, 'connectionId' => $connectionId, 'runId' => $runId]);
                }
                $this->em->clear();
                $this->logger->log(in_array($category, ['rate_limited', 'temporary'], true) ? 'warning' : 'error', 'MoySklad counterparty sync failed', ['companyId' => $companyId, 'connectionId' => $connectionId, 'runId' => $runId, 'category' => $category, 'exceptionClass' => $error::class]);
                throw new CounterpartySyncException($category, $error instanceof CounterpartySyncException ? $error->retryAfterMs : null, $runId);
            }
        } finally {
            try {
                $db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $lockParams);
            } catch (\Throwable) {
                // A lost database session has already released its session lock.
            }
        }
    }

    private function now(): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $timezone);

        return new \DateTimeImmutable($now->format('Y-m-d H:i:s.v'), $timezone);
    }
}
