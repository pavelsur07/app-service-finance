<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:marketplace:wb-finance:diagnostics',
    description: 'Read-only diagnostics for WB finance limiter, cooldown, queues and sync statuses.',
)]
final class WbFinanceDiagnosticsCommand extends Command
{
    private const COOLDOWN_PATTERN = 'wb_finance:sales_reports:cooldown:*';
    private const WB_QUEUE_STREAM = 'messages_wb_finance';
    private const WB_QUEUE_DELAYED = 'messages_wb_finance__queue';

    public function __construct(
        #[Autowire(service: 'session.redis')]
        private readonly object $redisClient,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly object $rateLimiterCachePool,
        #[Autowire(service: 'limiter.wb_finance')]
        private readonly object $wbFinanceLimiter,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('WB Finance diagnostics');

        $this->renderRedisAndLimiter($io);
        $this->renderCooldownKeys($io);
        $this->renderQueueSizes($io);
        $this->renderStatusCounts($io);
        $this->renderRetryAndErrorDiagnostics($io);
        $this->renderRawDiagnostics($io);

        $io->success('Diagnostics completed. Command is read-only.');

        return Command::SUCCESS;
    }

    private function renderRedisAndLimiter(SymfonyStyle $io): void
    {
        $io->section('Redis / limiter');
        $io->table(['Check', 'Value'], [
            ['session.redis class', $this->redisClient::class],
            ['cache.rate_limiter class', $this->rateLimiterCachePool::class],
            ['limiter.wb_finance service', 'present: '.$this->wbFinanceLimiter::class],
        ]);
    }

    private function renderCooldownKeys(SymfonyStyle $io): void
    {
        $io->section('Redis cooldown keys');

        try {
            $keys = $this->redisKeys(self::COOLDOWN_PATTERN);
            sort($keys);

            if ([] === $keys) {
                $io->writeln('No cooldown keys found.');

                return;
            }

            $rows = [];
            foreach ($keys as $key) {
                $timestamp = $this->redisGet($key);
                $ttl = $this->redisTtl($key);
                $cooldownUntil = $this->formatTimestamp($timestamp);
                $rows[] = [$key, $this->bucketTypeFromCooldownKey($key), $cooldownUntil, null === $ttl ? 'n/a' : (string) $ttl];
            }

            $io->table(['key', 'bucket_type', 'cooldown_until', 'ttl_seconds'], $rows);
        } catch (\Throwable $e) {
            $io->warning('Cannot read Redis cooldown keys: '.$e->getMessage());
        }
    }

    private function renderQueueSizes(SymfonyStyle $io): void
    {
        $io->section('Redis queue sizes');
        $rows = [];

        foreach ([
            'XLEN '.self::WB_QUEUE_STREAM => static fn (self $command): ?int => $command->redisXLen(self::WB_QUEUE_STREAM),
            'ZCARD '.self::WB_QUEUE_DELAYED => static fn (self $command): ?int => $command->redisZCard(self::WB_QUEUE_DELAYED),
        ] as $label => $reader) {
            try {
                $value = $reader($this);
                $rows[] = [$label, null === $value ? 'n/a' : (string) $value];
            } catch (\Throwable $e) {
                $rows[] = [$label, 'error: '.$e->getMessage()];
            }
        }

        $io->table(['metric', 'value'], $rows);
    }

    private function renderStatusCounts(SymfonyStyle $io): void
    {
        $io->section('WB sales_report sync_status counts');

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT status, COUNT(*) AS count
                 FROM marketplace_financial_report_sync_statuses
                 WHERE marketplace = 'wildberries' AND report_type = 'sales_report'
                 GROUP BY status
                 ORDER BY status",
            );
            $countByStatus = [];
            foreach ($rows as $row) {
                $countByStatus[(string) $row['status']] = (int) $row['count'];
            }

            $io->table(['status', 'count'], array_map(
                static fn (string $status): array => [$status, (string) ($countByStatus[$status] ?? 0)],
                ['queued', 'failed', 'success', 'loading', 'raw_loaded', 'processing'],
            ));
        } catch (\Throwable $e) {
            $io->warning('Cannot read sync_status counts: '.$e->getMessage());
        }
    }

    private function renderRetryAndErrorDiagnostics(SymfonyStyle $io): void
    {
        $io->section('Retry / 429 diagnostics');

        $rows = [];
        try {
            $dueRetry = $this->connection->fetchOne(
                "SELECT COUNT(*)
                 FROM marketplace_financial_report_sync_statuses
                 WHERE marketplace = 'wildberries'
                   AND report_type = 'sales_report'
                   AND status IN ('queued', 'failed')
                   AND next_retry_at IS NOT NULL
                   AND next_retry_at <= NOW()",
            );
            $rows[] = ['due_retry_count', (string) (int) $dueRetry];
        } catch (\Throwable $e) {
            $rows[] = ['due_retry_count', 'error: '.$e->getMessage()];
        }

        try {
            $last429 = $this->connection->fetchOne(
                'SELECT MAX(created_at)
                 FROM marketplace_financial_report_sync_errors
                 WHERE status_code = 429',
            );
            $rows[] = ['last_429_time', false === $last429 || null === $last429 ? 'n/a' : (string) $last429];
        } catch (\Throwable $e) {
            $rows[] = ['last_429_time', 'error: '.$e->getMessage()];
        }

        $io->table(['metric', 'value'], $rows);
    }

    private function renderRawDiagnostics(SymfonyStyle $io): void
    {
        $io->section('Raw/status consistency');

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT s.company_id, s.connection_id, s.business_date, s.raw_document_id, r.processed_at, r.records_count
                 FROM marketplace_financial_report_sync_statuses s
                 INNER JOIN marketplace_raw_documents r ON r.id = s.raw_document_id
                 WHERE s.marketplace = 'wildberries'
                   AND s.report_type = 'sales_report'
                   AND r.marketplace = 'wildberries'
                   AND r.document_type = 'sales_report'
                   AND r.processing_status = 'completed'
                 ORDER BY COALESCE(r.processed_at, r.synced_at) DESC
                 LIMIT 20",
            );
            $io->writeln('Last successful raw per connection/date:');
            $this->renderRows($io, ['company_id', 'connection_id', 'business_date', 'raw_document_id', 'processed_at', 'records_count'], $rows);
        } catch (\Throwable $e) {
            $io->warning('Cannot read last successful raw documents: '.$e->getMessage());
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT s.company_id, s.connection_id, s.business_date, s.status, s.raw_document_id, r.processing_status, r.processed_at
                 FROM marketplace_financial_report_sync_statuses s
                 INNER JOIN marketplace_raw_documents r ON r.id = s.raw_document_id
                 WHERE s.marketplace = 'wildberries'
                   AND s.report_type = 'sales_report'
                   AND r.marketplace = 'wildberries'
                   AND r.document_type = 'sales_report'
                   AND r.processing_status = 'completed'
                   AND s.status IN ('failed', 'queued')
                 ORDER BY COALESCE(r.processed_at, r.synced_at) DESC
                 LIMIT 50",
            );
            $io->writeln('Mismatch: raw completed exists, but sync_status failed/queued:');
            $this->renderRows($io, ['company_id', 'connection_id', 'business_date', 'status', 'raw_document_id', 'processing_status', 'processed_at'], $rows);
        } catch (\Throwable $e) {
            $io->warning('Cannot read raw/status mismatches: '.$e->getMessage());
        }
    }

    /** @param list<string> $headers @param list<array<string,mixed>> $rows */
    private function renderRows(SymfonyStyle $io, array $headers, array $rows): void
    {
        if ([] === $rows) {
            $io->writeln('none');

            return;
        }

        $io->table($headers, array_map(
            static fn (array $row): array => array_map(static fn (string $header): string => (string) ($row[$header] ?? ''), $headers),
            $rows,
        ));
    }

    /** @return list<string> */
    private function redisKeys(string $pattern): array
    {
        $keys = [];

        if (is_a($this->redisClient, 'Redis')) {
            $iterator = null;
            do {
                $batch = $this->redisClient->scan($iterator, $pattern, 100);
                if (is_array($batch)) {
                    foreach ($batch as $key) {
                        $keys[] = (string) $key;
                    }
                }
            } while (null !== $iterator && 0 !== $iterator && '0' !== $iterator);

            return array_values(array_unique($keys));
        }

        $cursor = '0';
        do {
            $result = $this->redisClient->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if (!is_array($result) || !array_key_exists(0, $result) || !array_key_exists(1, $result) || !is_array($result[1])) {
                break;
            }

            $cursor = (string) $result[0];
            foreach ($result[1] as $key) {
                $keys[] = (string) $key;
            }
        } while ('0' !== $cursor);

        return array_values(array_unique($keys));
    }

    private function bucketTypeFromCooldownKey(string $key): string
    {
        $bucket = substr($key, strlen('wb_finance:sales_reports:cooldown:'));
        if ('global' === $bucket) {
            return 'global';
        }
        if (str_starts_with($bucket, 'connection:')) {
            return 'connection';
        }

        return 'seller/account';
    }

    private function redisGet(string $key): ?string
    {
        $value = $this->redisClient->get($key);
        if (false === $value || null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private function redisTtl(string $key): ?int
    {
        $ttl = $this->redisClient->ttl($key);
        if (false === $ttl || null === $ttl) {
            return null;
        }

        return (int) $ttl;
    }

    private function redisXLen(string $key): ?int
    {
        $value = $this->redisClient->xlen($key);
        if (false === $value || null === $value) {
            return null;
        }

        return (int) $value;
    }

    private function redisZCard(string $key): ?int
    {
        $value = $this->redisClient->zcard($key);
        if (false === $value || null === $value) {
            return null;
        }

        return (int) $value;
    }

    private function formatTimestamp(?string $timestamp): string
    {
        if (null === $timestamp || !ctype_digit($timestamp)) {
            return 'n/a';
        }

        return (new \DateTimeImmutable('@'.$timestamp))->format(\DateTimeInterface::ATOM);
    }
}
