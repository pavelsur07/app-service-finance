<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:marketplace-categories:discover',
    description: 'Discovers unknown marketplace external categories from normalized Ingestion transactions.',
)]
final class MarketplaceCategoryDiscoverCommand extends Command
{
    public function __construct(private readonly DiscoverExternalCategoriesAction $discoverExternalCategories)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Marketplace source.', IngestSource::OZON->value)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max grouped unknown categories to scan.', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = IngestSource::from((string) $input->getOption('source'));
            $limit = (int) $input->getOption('limit');
            $stats = ($this->discoverExternalCategories)($source, $limit);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Marketplace category discovery');
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $metric, int $value): array => [$metric, (string) $value],
                array_keys($stats),
                array_values($stats),
            ),
        );
        $io->success('Marketplace category discovery finished.');

        return Command::SUCCESS;
    }
}
