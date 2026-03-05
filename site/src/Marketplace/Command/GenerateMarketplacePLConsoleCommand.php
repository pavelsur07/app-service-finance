<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Finance\Enum\PLDocumentStream;
use App\Marketplace\Application\Command\GeneratePLCommand;
use App\Marketplace\Application\GeneratePLFromMarketplaceAction;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Генерация документа ОПиУ из данных маркетплейса.
 *
 * Примеры:
 *   php bin/console app:marketplace:generate-pl <companyId> wildberries revenue --from=2024-01-01 --to=2024-01-31
 *   php bin/console app:marketplace:generate-pl <companyId> wildberries --all-streams --from=2024-01-01 --to=2024-01-31
 */
#[AsCommand(
    name: 'app:marketplace:generate-pl',
    description: 'Генерация документа ОПиУ из данных маркетплейса',
)]
final class GenerateMarketplacePLConsoleCommand extends Command
{
    public function __construct(
        private readonly GeneratePLFromMarketplaceAction $action,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $marketplaces = implode(', ', array_map(fn ($m) => $m->value, MarketplaceType::cases()));
        $streams = implode(', ', array_map(fn ($s) => $s->value, PLDocumentStream::cases()));

        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addArgument('marketplace', InputArgument::REQUIRED, "Маркетплейс: {$marketplaces}")
            ->addArgument('stream', InputArgument::OPTIONAL, "Поток: {$streams}")
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Начало периода (Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Конец периода (Y-m-d)')
            ->addOption('all-streams', null, InputOption::VALUE_NONE, 'Генерировать для всех потоков (revenue + costs)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'UUID пользователя', 'system');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getArgument('companyId');
        $marketplace = $input->getArgument('marketplace');
        $periodFrom = $input->getOption('from');
        $periodTo = $input->getOption('to');
        $actorUserId = $input->getOption('actor');

        // Валидация периода
        if (!$periodFrom || !$periodTo) {
            $io->error('Укажите период: --from=2026-02-01 --to=2026-03-31');

            return Command::FAILURE;
        }

        // Валидация marketplace
        $marketplaceEnum = MarketplaceType::tryFrom($marketplace);
        if (!$marketplaceEnum) {
            $io->error(sprintf(
                'Неизвестный маркетплейс: %s. Допустимые: %s',
                $marketplace,
                implode(', ', array_map(fn ($m) => $m->value, MarketplaceType::cases()))
            ));

            return Command::FAILURE;
        }

        // Определяем потоки для генерации
        $allStreams = $input->getOption('all-streams');
        if ($allStreams) {
            $streams = [PLDocumentStream::REVENUE, PLDocumentStream::COSTS];
        } else {
            $streamArg = $input->getArgument('stream');
            if (!$streamArg) {
                $io->error('Укажите stream (revenue/costs) или используйте --all-streams');

                return Command::FAILURE;
            }

            $streamEnum = PLDocumentStream::tryFrom($streamArg);
            if (!$streamEnum) {
                $io->error(sprintf(
                    'Неизвестный поток: %s. Допустимые: %s',
                    $streamArg,
                    implode(', ', array_map(fn ($s) => $s->value, PLDocumentStream::cases()))
                ));

                return Command::FAILURE;
            }
            $streams = [$streamEnum];
        }

        $io->title(sprintf(
            'Генерация ОПиУ: %s | %s – %s',
            $marketplaceEnum->value,
            $periodFrom,
            $periodTo,
        ));

        $totalDocuments = 0;

        foreach ($streams as $stream) {
            $io->section(sprintf('Поток: %s', $stream->getDisplayName()));

            try {
                $command = new GeneratePLCommand(
                    companyId: $companyId,
                    marketplace: $marketplace,
                    stream: $stream->value,
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    actorUserId: $actorUserId,
                );

                $documentId = ($this->action)($command);

                if ($documentId) {
                    $io->success(sprintf('Документ создан: %s', $documentId));
                    ++$totalDocuments;
                } else {
                    $io->note('Нет данных для обработки');
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Ошибка: %s', $e->getMessage()));
                if ($output->isVerbose()) {
                    $io->error($e->getTraceAsString());
                }

                return Command::FAILURE;
            }
        }

        $io->success(sprintf('Готово! Создано документов: %d', $totalDocuments));

        return Command::SUCCESS;
    }
}
