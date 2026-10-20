<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\Application\BackfillCompanyOnboardingAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:company:onboarding-backfill',
    description: 'Дозаполняет стартовые данные компаний, созданных до онбординга: касса, дерево ОПиУ, структура и счета Баланса.',
)]
final class BackfillCompanyOnboardingCommand extends Command
{
    public function __construct(private readonly BackfillCompanyOnboardingAction $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Одна компания (UUID); без опции — все')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Записать изменения; без опции — только отчёт')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число компаний с пробелами из последнего dry-run (обязательно с --execute)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = true === $input->getOption('execute');
        $companyId = $input->getOption('company');
        $companyId = \is_string($companyId) && '' !== $companyId ? $companyId : null;
        $expected = $input->getOption('expected-count');
        if (\is_string($expected) && !ctype_digit($expected)) {
            $io->error('--expected-count — неотрицательное целое число из последнего dry-run.');

            return Command::INVALID;
        }
        $expected = \is_string($expected) ? (int) $expected : null;

        try {
            $result = ($this->backfill)($companyId, $execute, $expected);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $rows = [['mode' => $execute ? 'execute' : 'dry-run'], ['companies' => $result->companies], ['candidates' => \count($result->candidates)]];
        foreach ($result->totals as $item => $count) {
            $rows[] = [$item => $count];
        }
        if ($execute) {
            $rows[] = ['completed' => $result->completed];
            $rows[] = ['failed' => $result->failed];
        }
        $io->definitionList(...$rows);

        if ($output->isVerbose()) {
            $io->listing(array_map(
                static fn (string $id, array $gaps): string => $id.' '.implode(', ', array_map(static fn (string $item, int $count): string => $item.'='.$count, array_keys($gaps), $gaps)),
                array_keys($result->candidates),
                $result->candidates,
            ));
        }

        if ($result->failed > 0) {
            $io->error('Дозаполнение прервано ошибкой, смотрите лог. Уже дозаполненные компании сохранены; для продолжения — новый dry-run и --execute с его --expected-count.');

            return Command::FAILURE;
        }
        if ($execute) {
            $io->success(sprintf('Дозаполнено компаний: %d.', $result->completed));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute и --expected-count из этого отчёта (-v покажет компании и пробелы).');
        }

        return Command::SUCCESS;
    }
}
