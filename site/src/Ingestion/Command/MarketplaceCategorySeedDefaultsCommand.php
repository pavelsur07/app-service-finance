<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:marketplace-categories:seed-defaults',
    description: 'Seeds default global marketplace external category mappings.',
)]
final class MarketplaceCategorySeedDefaultsCommand extends Command
{
    public function __construct(private readonly SeedExternalCategoryMappingsAction $seedDefaults)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Marketplace source.', IngestSource::OZON->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = IngestSource::from((string) $input->getOption('source'));
            $stats = ($this->seedDefaults)($source);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Marketplace category default mappings');
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $metric, int $value): array => [$metric, (string) $value],
                array_keys($stats),
                array_values($stats),
            ),
        );
        $io->success('Default marketplace category mappings are up to date.');

        return Command::SUCCESS;
    }
}
