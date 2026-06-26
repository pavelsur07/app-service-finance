<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\RefreshOzonAccrualCategoryMetadataAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:refresh-category-metadata',
    description: 'Refreshes Ozon accrual category metadata on already normalized canonical transactions.',
)]
final class OzonAccrualRefreshCategoryMetadataCommand extends Command
{
    public function __construct(private readonly RefreshOzonAccrualCategoryMetadataAction $refreshMetadata)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start accrual date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End accrual date YYYY-MM-DD.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Raw records to process, 1..500.', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected records and planned metadata updates without writing.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Refresh metadata synchronously in this process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            [$from, $to] = $this->requiredDateWindow($input);
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limit = $this->intOption($input, 'limit', 1, 500);
            $mode = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRecords = $this->refreshMetadata->rawRecords($companyId, $from, $to, $shopRef, $limit);

        $io->title('Ozon accrual category metadata refresh');
        $this->printRawRecords($io, $rawRecords);

        if ([] === $rawRecords) {
            return Command::SUCCESS;
        }

        $dryRun = 'dry-run' === $mode;
        $resultRows = $this->refreshMetadata->refresh($companyId, $rawRecords, $dryRun);
        $this->printActionResult($io, $resultRows);

        $failed = array_values(array_filter($resultRows, static fn (array $row): bool => 'error' === $row['status']));
        if ([] !== $failed) {
            $io->warning(sprintf('Metadata refresh finished with %d failed raw records.', count($failed)));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry-run only. No canonical transactions were changed.');

            return Command::SUCCESS;
        }

        $updated = array_sum(array_map(static fn (array $row): int => (int) $row['updated'], $resultRows));
        $io->success(sprintf('Refreshed Ozon category metadata on %d canonical transactions.', $updated));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Selected raw records');
        if ([] === $rawRecords) {
            $io->writeln('No done Ozon accrual by-day raw records found for the selected period.');

            return;
        }

        $io->table(
            ['windowFrom', 'windowTo', 'rawId', 'externalId', 'shopRef', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) ($row['window_from'] ?? ''),
                (string) ($row['window_to'] ?? ''),
                (string) $row['id'],
                (string) $row['external_id'],
                (string) $row['shop_ref'],
                (string) $row['normalization_status'],
                (string) $row['byte_size'],
                (string) $row['fetched_at'],
            ], $rawRecords),
        );
    }

    /**
     * @param list<array<string, string|int>> $resultRows
     */
    private function printActionResult(SymfonyStyle $io, array $resultRows): void
    {
        $io->section('Metadata refresh result');
        if ([] === $resultRows) {
            $io->writeln('No records were processed.');

            return;
        }

        $io->table(
            ['rawId', 'status', 'scanned', 'matched', 'updated', 'unchanged', 'missing', 'error'],
            array_map(static fn (array $row): array => [
                (string) $row['rawId'],
                (string) $row['status'],
                (string) $row['scanned'],
                (string) $row['matched'],
                (string) $row['updated'],
                (string) $row['unchanged'],
                (string) $row['missing'],
                (string) ($row['error'] ?? ''),
            ], $resultRows),
        );
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function requiredDateWindow(InputInterface $input): array
    {
        $from = $this->dateOption($input, 'from');
        $to = $this->dateOption($input, 'to');
        if ($from > $to) {
            throw new \InvalidArgumentException('--from cannot be later than --to.');
        }

        return [$from, $to];
    }

    private function dateOption(InputInterface $input, string $name): \DateTimeImmutable
    {
        $value = trim((string) $input->getOption($name));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (int) $input->getOption($name);

        return max($min, min($max, $value));
    }

    private function mode(InputInterface $input): string
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('execute-inline') ? 'execute-inline' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute-inline.');
        }

        return $modes[0];
    }
}
