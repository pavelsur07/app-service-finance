<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Service\OzonAccrualListingRelinker;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:relink-listings',
    description: 'Relinks existing Ozon accrual ingestion transactions to Marketplace listings from stored source data or raw records.',
)]
final class OzonAccrualRelinkListingsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly OzonAccrualListingRelinker $relinker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional occurred_at start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional occurred_at end date YYYY-MM-DD.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum transactions to inspect, 1..5000.', 500)
            ->addOption('component-filter', null, InputOption::VALUE_REQUIRED, 'Component filter: all or linkable.', OzonAccrualListingRelinker::COMPONENT_FILTER_ALL)
            ->addOption('summary-only', null, InputOption::VALUE_NONE, 'Print only settings and result metrics.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON result.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned relinks without writing.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Persist listing links.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Ozon accrual relink listings is already running.</comment>');

            return Command::SUCCESS;
        }

        try {
            return $this->runRelink($input, new SymfonyStyle($input, $output));
        } finally {
            $this->release();
        }
    }

    private function runRelink(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $from = $this->optionalDateOption($input, 'from');
            $to = $this->optionalDateOption($input, 'to');
            $limit = $this->intOption($input, 'limit', 1, 5000);
            $componentFilter = $this->componentFilter($input);
            $summaryOnly = (bool) $input->getOption('summary-only');
            $json = (bool) $input->getOption('json');
            $execute = $this->mode($input);

            if (null !== $from && null !== $to && $from > $to) {
                throw new \InvalidArgumentException('--from must be before or equal to --to.');
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $result = $this->relinker->relink(
            companyId: $companyId,
            from: $from,
            to: $to,
            limit: $limit,
            execute: $execute,
            shopRef: $shopRef,
            componentFilter: $componentFilter,
            includeRows: !$summaryOnly && !$json,
        );

        if ($json) {
            $io->writeln((string) json_encode([
                'mode' => $execute ? 'execute' : 'dry-run',
                'companyId' => $companyId,
                'shopRef' => $shopRef,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
                'limit' => $limit,
                'componentFilter' => $componentFilter,
                'result' => [
                    'selected' => $result['selected'],
                    'resolved' => $result['resolved'],
                    'updated' => $result['updated'],
                    'wouldCreateListings' => $result['wouldCreateListings'],
                    'unresolved' => $result['unresolved'],
                ],
            ], \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('Ozon accrual listing relink');
        $io->table(
            ['setting', 'value'],
            [
                ['mode', $execute ? 'execute' : 'dry-run'],
                ['companyId', $companyId ?? 'all'],
                ['shopRef', $shopRef ?? 'all'],
                ['from', $from?->format('Y-m-d') ?? 'any'],
                ['to', $to?->format('Y-m-d') ?? 'any'],
                ['limit', (string) $limit],
                ['componentFilter', $componentFilter],
            ],
        );

        if (!$summaryOnly && [] !== $result['rows']) {
            $io->section('Selected transactions');
            $io->table(
                ['companyId', 'occurredAt', 'externalId', 'listingSku', 'listingId', 'status'],
                array_map(static fn (array $row): array => [
                    $row['companyId'],
                    $row['occurredAt'],
                    $row['externalId'],
                    $row['listingSku'],
                    $row['listingId'],
                    $row['status'],
                ], $result['rows']),
            );
        }

        $io->section('Result');
        $io->table(
            ['metric', 'value'],
            [
                ['selected', (string) $result['selected']],
                ['resolved', (string) $result['resolved']],
                ['updated', (string) $result['updated']],
                ['wouldCreateListings', (string) $result['wouldCreateListings']],
                ['unresolved', (string) $result['unresolved']],
            ],
        );

        if (!$execute) {
            $io->note('Dry-run only. Use --execute to persist listing links.');
        }

        return Command::SUCCESS;
    }

    private function componentFilter(InputInterface $input): string
    {
        $value = trim((string) $input->getOption('component-filter'));
        if (in_array($value, [OzonAccrualListingRelinker::COMPONENT_FILTER_ALL, OzonAccrualListingRelinker::COMPONENT_FILTER_LINKABLE], true)) {
            return $value;
        }

        throw new \InvalidArgumentException('--component-filter must be "all" or "linkable".');
    }

    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid UUID.', $name));
        }

        return $value;
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must use YYYY-MM-DD format.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) ($input->getOption($name) ?? ''));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $raw = $input->getOption($name);
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(sprintf('--%s must be numeric.', $name));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $min, $max));
        }

        return $value;
    }

    private function mode(InputInterface $input): bool
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $execute = (bool) $input->getOption('execute');

        if ($dryRun === $execute) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return $execute;
    }
}
