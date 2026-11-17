<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\Category\ReplaceUnusedCashflowTreeWithCoreAction;
use App\Cash\Exception\CashflowCoreTemplateException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:cashflow-core:apply',
    description: 'Базовое дерево ДДС (core.yaml) для существующей компании: заменяет неиспользуемые статьи. Без --execute только план.',
)]
final class ApplyCashflowCoreToCompanyCommand extends Command
{
    public function __construct(
        private readonly ReplaceUnusedCashflowTreeWithCoreAction $replace,
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
            $plan = $this->replace->plan($companyId);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($plan->alreadyApplied) {
            $io->success('Базовое дерево ДДС уже применено, менять нечего.');

            return Command::SUCCESS;
        }
        if ([] !== $plan->blocked) {
            $io->error('Замена невозможна:');
            $io->listing($plan->blocked);

            return Command::FAILURE;
        }

        $io->section(sprintf('Будет удалено статей: %d', \count($plan->removable)));
        if ([] !== $plan->removable) {
            $io->listing($plan->removable);
        }
        $io->writeln(sprintf('ИИ-подсказок станет stale: %d', $plan->staleSuggestions));
        $io->writeln(sprintf('Будет создано узлов ядра: %d', $plan->coreNodes));

        if (!$input->getOption('execute')) {
            $io->note('Режим просмотра: данные не изменены. Для применения добавьте --execute.');

            return Command::SUCCESS;
        }

        try {
            $created = $this->entityManager->wrapInTransaction(fn (): int => ($this->replace)($companyId));
        } catch (CashflowCoreTemplateException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Создано узлов ядра: %d.', $created));

        return Command::SUCCESS;
    }
}
