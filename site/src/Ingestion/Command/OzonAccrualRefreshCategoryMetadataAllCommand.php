<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Application\Action\RebuildMarketplaceCategoryIdentitiesAction;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:refresh-category-metadata-all',
    description: 'Refreshes Ozon accrual category metadata for all stored by-day raw records in one run.',
)]
final class OzonAccrualRefreshCategoryMetadataAllCommand extends Command
{
    use OzonAccrualCommandHelperTrait;

    public function __construct(
        private readonly OzonAccrualCategoryMetadataBulkRunnerInterface $bulkRunner,
        private readonly DiscoverExternalCategoriesAction $discoverCategories,
        private readonly RebuildMarketplaceCategoryIdentitiesAction $rebuildIdentities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional start accrual date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional end accrual date YYYY-MM-DD.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit-per-shop', null, InputOption::VALUE_REQUIRED, 'Raw records to process per company/shop, 1..500.', 500)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected targets and planned metadata updates without writing.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Refresh metadata synchronously in this process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $from = $this->optionalDateOption($input, 'from');
            $to = $this->optionalDateOption($input, 'to');
            if (null !== $from && null !== $to && $from > $to) {
                throw new \InvalidArgumentException('--from cannot be later than --to.');
            }

            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limitPerShop = $this->intOption($input, 'limit-per-shop', 1, 500);
            $mode = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $dryRun = 'dry-run' === $mode;
        $targets = $this->bulkRunner->targets($from, $to, $companyId, $shopRef);

        $io->title('Ozon accrual category metadata bulk refresh');
        $io->section('Selected company/shop targets');
        $this->printTargets($io, $targets);

        if ([] === $targets) {
            return Command::SUCCESS;
        }

        $refreshResult = $this->bulkRunner->refreshTargets($targets, $from, $to, $limitPerShop, $dryRun);
        $totals = $refreshResult['totals'];

        $io->section('Bulk metadata refresh result');
        $this->printMetrics($io, $totals);

        if ($totals['failedRawRecords'] > 0 || $totals['failedTargets'] > 0) {
            $io->warning(sprintf(
                'Metadata refresh finished with %d failed raw records and %d failed targets.',
                $totals['failedRawRecords'],
                $totals['failedTargets'],
            ));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry-run only. No canonical transactions or taxonomy rows were changed.');

            return Command::SUCCESS;
        }

        $discover = ($this->discoverCategories)(IngestSource::OZON, 5000);
        $rebuild = $this->rebuildIdentities->rebuild(IngestSource::OZON, execute: true);

        $io->section('Taxonomy follow-up');
        $io->table(
            ['action', 'metric', 'value'],
            array_merge(
                $this->metricRows('discover', $discover),
                $this->metricRows('rebuild-identities', $rebuild),
            ),
        );

        $io->success(sprintf('Refreshed Ozon category metadata on %d canonical transactions.', $totals['updated']));

        return Command::SUCCESS;
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
