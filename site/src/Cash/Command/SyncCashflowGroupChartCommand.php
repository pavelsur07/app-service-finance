<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\Category\SyncCashflowGroupChartAction;
use App\Cash\Application\DTO\CashflowGroupChartSyncPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:group-chart:sync',
    description: 'Выравнивает дерево ДДС компании по эталону группы (головная компания или core.yaml). Ничего не удаляет. Без --execute только план.',
)]
final class SyncCashflowGroupChartCommand extends Command
{
    public function __construct(
        private readonly SyncCashflowGroupChartAction $sync,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить; без опции — только план');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = (string) $input->getArgument('companyId');

        try {
            $plan = $this->sync->plan($companyId);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $plan);
        if ($plan->isEmpty() && [] !== $plan->conflicts) {
            $io->warning('Автоматически менять нечего, но сопоставление не завершено: конфликты выше разберите вручную.');

            return Command::SUCCESS;
        }
        if ($plan->isEmpty()) {
            $io->success('Дерево ДДС совпадает с эталоном, менять нечего.');

            return Command::SUCCESS;
        }
        if (!$input->getOption('execute')) {
            $io->note('Режим просмотра: данные не изменены. Для применения добавьте --execute.');

            return Command::SUCCESS;
        }

        try {
            /** @var CashflowGroupChartSyncPlan $result */
            $result = $this->entityManager->wrapInTransaction(fn (): CashflowGroupChartSyncPlan => ($this->sync)($companyId));
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Привязано: %d, создано: %d, кодов головной: %d, конфликтов: %d.',
            \count($result->bind),
            \count($result->create),
            $result->headCodes,
            \count($result->conflicts),
        ));

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, CashflowGroupChartSyncPlan $plan): void
    {
        $io->writeln('head' === $plan->source ? sprintf('Эталон: дерево головной компании %s', $plan->headCompanyId) : 'Эталон: базовое дерево (core.yaml)');
        if ($plan->headCodes > 0) {
            $io->writeln(sprintf('Статьям головной компании будет присвоен код: %d', $plan->headCodes));
        }
        foreach (['Будет привязано' => $plan->bind, 'Будет создано' => $plan->create, 'Конфликты (не трогаем)' => $plan->conflicts] as $title => $lines) {
            if ([] !== $lines) {
                $io->section(sprintf('%s: %d', $title, \count($lines)));
                $io->listing($lines);
            }
        }
    }
}
