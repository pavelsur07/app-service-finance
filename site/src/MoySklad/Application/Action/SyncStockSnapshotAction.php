<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\StockSnapshotRunner;
use App\MoySklad\Application\StoreSyncRunner;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStockSnapshotAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private MoySkladConnectionWriteRepository $connections,
        private StoreSyncRunner $stores,
        private StockSnapshotRunner $stock,
        private ConnectionTokenCodec $codec,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId): ?string
    {
        try {
            $connection = $this->connections->findByIdAndCompanyId($connectionId, $companyId);
        } catch (\Throwable) {
            $this->throwInternal($companyId, $connectionId);
        }
        if (null === $connection || !$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
            return null;
        }

        $db = $this->em->getConnection();
        $lockParams = ['namespace' => 'moysklad-stock-snapshot', 'key' => $connectionId];
        try {
            $locked = $db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))', $lockParams);
        } catch (\Throwable) {
            $this->throwInternal($companyId, $connectionId);
        }
        if (!in_array($locked, [true, 1, '1', 't'], true)) {
            return null;
        }

        $this->logger->info('MoySklad stock snapshot sync started', ['companyId' => $companyId, 'connectionId' => $connectionId]);
        try {
            $this->em->refresh($connection);
            if (!$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
                return null;
            }
            try {
                $token = $this->codec->accessTokenFor($connection);
            } catch (\Throwable) {
                throw new StockSyncException('internal');
            }
            if (null === $token || '' === $token) {
                throw new StockSyncException('auth');
            }

            $activeStoreIds = $this->stores->run($companyId, $connectionId, $connection->getAccountId(), $token);
            $snapshotId = $this->stock->run($companyId, $connectionId, $activeStoreIds, $token);
            $this->logger->info('MoySklad stock snapshot sync completed', ['companyId' => $companyId, 'connectionId' => $connectionId, 'snapshotId' => $snapshotId]);

            return $snapshotId;
        } catch (StockSyncException $error) {
            $this->logger->log(in_array($error->category, ['rate_limited', 'temporary'], true) ? 'warning' : 'error', 'MoySklad stock snapshot sync failed', [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'runId' => $error->runId,
                'category' => $error->category,
            ]);
            throw $error;
        } catch (\Throwable) {
            $this->logger->error('MoySklad stock snapshot sync failed', [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'runId' => null,
                'category' => 'internal',
            ]);
            throw new StockSyncException('internal');
        } finally {
            try {
                $db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $lockParams);
            } catch (\Throwable) {
                // A lost database session has already released its session lock.
            }
        }
    }

    private function throwInternal(string $companyId, string $connectionId): never
    {
        $this->logger->error('MoySklad stock snapshot sync failed', [
            'companyId' => $companyId,
            'connectionId' => $connectionId,
            'runId' => null,
            'category' => 'internal',
        ]);
        throw new StockSyncException('internal');
    }
}
