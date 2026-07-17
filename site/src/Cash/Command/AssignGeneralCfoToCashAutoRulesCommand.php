<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\Service\CashTransactionAutoRuleGeneralCfoAssigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash-auto-rules:assign-general-cfo',
    description: 'Назначает CFO_GENERAL активным правилам с PROJECT_GENERAL; без --execute работает read-only',
)]
final class AssignGeneralCfoToCashAutoRulesCommand extends Command
{
    public function __construct(
        private readonly CashTransactionAutoRuleGeneralCfoAssigner $assigner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Сохранить назначения после успешной полной проверки')
            ->addOption('actor-user-id', null, InputOption::VALUE_REQUIRED, 'UUID пользователя, согласовавшего изменение')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число кандидатов из последнего dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = true === $input->getOption('execute');
        $actorUserId = $input->getOption('actor-user-id');
        $actorUserId = is_string($actorUserId) && '' !== trim($actorUserId) ? trim($actorUserId) : null;
        $expectedCount = $input->getOption('expected-count');
        $expectedCount = is_string($expectedCount) && ctype_digit($expectedCount) ? (int) $expectedCount : null;

        try {
            $result = $this->assigner->run($execute, $actorUserId, $expectedCount);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (\Throwable $exception) {
            $io->error(sprintf('Пакетное назначение не выполнено: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['mode' => $execute ? 'execute' : 'dry-run'],
            ['companies' => $result['companies']],
            ['candidates' => $result['candidates']],
            ['assignable' => $result['assignable']],
            ['blocked' => $result['blocked']],
            ['updated' => $result['updated']],
        );

        if ($result['blocked'] > 0) {
            $io->error('Назначение заблокировано: не для каждой компании доступна активная системная пара. Изменений нет.');

            return Command::FAILURE;
        }

        if ($execute) {
            $io->success(sprintf('ЦФО назначен правилам: %d.', $result['updated']));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute, --actor-user-id и --expected-count из этого отчёта.');
        }

        return Command::SUCCESS;
    }
}
