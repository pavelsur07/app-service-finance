<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Infrastructure\Query\ExternalCategoryAdminQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:marketplace-categories:status',
    description: 'Shows marketplace external category mapping status.',
)]
final class MarketplaceCategoryStatusCommand extends Command
{
    public function __construct(private readonly ExternalCategoryAdminQuery $query)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Marketplace category taxonomy status');

        $unclassified = $this->query->unclassifiedOzonAccrualTransactions();
        $io->section('Ozon accrual report health');
        $io->table(
            ['metric', 'value'],
            [
                ['unclassifiedTransactions', (string) $unclassified['transactions']],
                ['unclassifiedGroups', (string) $unclassified['groups']],
            ],
        );

        $summary = $this->query->statusSummary();
        $io->section('External categories by status');
        if ([] === $summary) {
            $io->writeln('No external categories have been recorded yet.');
        } else {
            $io->table(
                ['source', 'resourceType', 'status', 'categories'],
                array_map(static fn (array $row): array => [
                    $row['source'],
                    $row['resource_type'],
                    $row['status'],
                    (string) $row['categories'],
                ], $summary),
            );
        }

        $latest = $this->query->latestCategories(20);
        $io->section('Latest categories');
        if ([] === $latest) {
            $io->writeln('No external categories.');
        } else {
            $io->table(
                ['status', 'source', 'scope', 'external', 'mappedTo', 'seen', 'lastSeenAt'],
                array_map(static fn (array $row): array => [
                    (string) $row['status'],
                    (string) $row['source'],
                    (string) $row['scope'],
                    (string) ($row['external_name'] ?? $row['external_type_id'] ?? $row['normalized_key']),
                    trim(sprintf('%s %s', (string) ($row['canonical_group'] ?? ''), (string) ($row['canonical_label'] ?? ''))),
                    (string) $row['seen_count'],
                    (string) $row['last_seen_at'],
                ], $latest),
            );
        }

        return Command::SUCCESS;
    }
}
