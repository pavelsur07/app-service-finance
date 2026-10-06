<?php

declare(strict_types=1);

namespace App\Balance\Command;

use App\Balance\Application\SeedMissingBalanceStructuresAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:balance:seed-missing-structure',
    description: 'Создаёт стартовую структуру баланса компаниям без неё; без --execute работает read-only',
)]
final class SeedMissingBalanceStructuresCommand extends Command
{
    public function __construct(private readonly SeedMissingBalanceStructuresAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Создать структуру после сверки числа кандидатов')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число кандидатов из последнего dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = true === $input->getOption('execute');
        $expected = $input->getOption('expected-count');
        $expected = is_string($expected) && ctype_digit($expected) ? (int) $expected : null;

        try {
            $result = ($this->action)($execute, $expected);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->definitionList(
            ['mode' => $execute ? 'execute' : 'dry-run'],
            ['companies' => $result['companies']],
            ['candidates' => \count($result['candidates'])],
            ['seeded' => $result['seeded']],
            ['failed' => $result['failed']],
        );
        if ($output->isVerbose()) {
            $io->listing($result['candidates']);
        }

        if ($result['failed'] > 0) {
            $io->error('Бэкфилл прерван ошибкой, смотрите лог. Повторный запуск продолжит с оставшихся компаний.');

            return Command::FAILURE;
        }
        if ($execute) {
            $io->success(sprintf('Структура создана для компаний: %d.', $result['seeded']));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute и --expected-count из этого отчёта (-v покажет список компаний).');
        }

        return Command::SUCCESS;
    }
}
