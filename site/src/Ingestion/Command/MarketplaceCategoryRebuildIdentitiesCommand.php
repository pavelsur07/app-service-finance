<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\RebuildMarketplaceCategoryIdentitiesAction;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:marketplace-categories:rebuild-identities',
    description: 'Rebuilds marketplace external category semantic identities from stored taxonomy metadata.',
)]
final class MarketplaceCategoryRebuildIdentitiesCommand extends Command
{
    public function __construct(private readonly RebuildMarketplaceCategoryIdentitiesAction $rebuildIdentities)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Marketplace source.', IngestSource::OZON->value)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Persist changes. Without this option the command is a dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = IngestSource::from((string) $input->getOption('source'));
            if (IngestSource::OZON !== $source) {
                throw new \InvalidArgumentException(sprintf('Identity rebuild is not implemented for source "%s".', $source->value));
            }

            $execute = (bool) $input->getOption('execute');
            $result = $this->rebuildIdentities->rebuild($source, $execute);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Marketplace category identity rebuild');
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $metric, int $value): array => [$metric, (string) $value],
                array_keys($result),
                array_values($result),
            ),
        );

        if (!$execute) {
            $io->note('Dry-run only. Use --execute to persist identity changes.');
        } else {
            $io->success('Marketplace category identities rebuilt.');
        }

        return Command::SUCCESS;
    }
}
