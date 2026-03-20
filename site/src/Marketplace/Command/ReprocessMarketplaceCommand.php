<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Переобработка raw-документов маркетплейса за период.
 *
 * Примеры:
 *   php bin/console app:marketplace:reprocess <companyId> ozon 2026-01-01 2026-01-31
 *   php bin/console app:marketplace:reprocess <companyId> ozon 2026-01-01 2026-01-31 --only=realization
 *   php bin/console app:marketplace:reprocess <companyId> ozon 2026-01-01 2026-01-31 --only=sales_report
 *   php bin/console app:marketplace:reprocess <companyId> ozon 2026-01-01 2026-01-31 --dry-run
 */
#[AsCommand(
    name: 'app:marketplace:reprocess',
    description: 'Переобработка raw-документов маркетплейса за период',
)]
final class ReprocessMarketplaceCommand extends Command
{
    public function __construct(
        private readonly ReprocessMarketplacePeriodAction $reprocessAction,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $marketplaces = implode(', ', array_map(fn ($m) => $m->value, MarketplaceType::cases()));

        $this
            ->addArgument('companyId',   InputArgument::REQUIRED, 'UUID компании')
            ->addArgument('marketplace', InputArgument::REQUIRED, "Маркетплейс: {$marketplaces}")
            ->addArgument('periodFrom',  InputArgument::REQUIRED, 'Начало периода (Y-m-d)')
            ->addArgument('periodTo',    InputArgument::REQUIRED, 'Конец периода (Y-m-d)')
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Тип документов: all | sales_report | realization (по умолчанию all)',
                'all',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Показать что будет обработано без реальных изменений',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId   = $input->getArgument('companyId');
        $marketplace = $input->getArgument('marketplace');
        $periodFrom  = $input->getArgument('periodFrom');
        $periodTo    = $input->getArgument('periodTo');
        $only        = $input->getOption('only');
        $dryRun      = $input->getOption('dry-run');

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $io->error(sprintf(
                'Неизвестный маркетплейс: %s. Допустимые: %s',
                $marketplace,
                implode(', ', array_map(fn ($m) => $m->value, MarketplaceType::cases())),
            ));

            return Command::FAILURE;
        }

        try {
            $dateFrom = new \DateTimeImmutable($periodFrom);
            $dateTo   = new \DateTimeImmutable($periodTo);
        } catch (\Exception) {
            $io->error('Неверный формат даты. Используйте Y-m-d, например: 2026-01-01');

            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Переобработка: %s | %s – %s%s',
            $marketplace,
            $periodFrom,
            $periodTo,
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($dryRun) {
            $io->note('DRY-RUN: реальных изменений не будет. Уберите --dry-run для запуска.');

            return Command::SUCCESS;
        }

        try {
            $result = ($this->reprocessAction)(
                companyId:   $companyId,
                marketplace: $marketplace,
                periodFrom:  $dateFrom,
                periodTo:    $dateTo,
                type:        $only,
            );
        } catch (\Throwable $e) {
            $io->error('Ошибка: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result['docs'] === 0) {
            $io->warning('Не найдено raw-документов за указанный период.');

            return Command::SUCCESS;
        }

        $io->section('Итог');
        $io->text(sprintf('  Документов обработано: %d', $result['docs']));

        if ($result['sales'] > 0)       { $io->text(sprintf('  Продажи:    %d', $result['sales'])); }
        if ($result['returns'] > 0)     { $io->text(sprintf('  Возвраты:   %d', $result['returns'])); }
        if ($result['costs'] > 0)       { $io->text(sprintf('  Затраты:    %d', $result['costs'])); }
        if ($result['realization'] > 0) { $io->text(sprintf('  Реализация: %d строк', $result['realization'])); }

        $io->success('Переобработка завершена.');

        return Command::SUCCESS;
    }
}
