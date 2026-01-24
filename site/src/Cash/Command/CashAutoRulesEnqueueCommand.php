<?php

namespace App\Cash\Command;

use App\Message\EnqueueAutoRulesForRange;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:cash:auto-rules:enqueue',
    description: 'Ставит в очередь асинхронное применение автоправил ДДС для диапазона транзакций.'
)]
final class CashAutoRulesEnqueueCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Начальная дата (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Конечная дата (YYYY-MM-DD)')
            ->addOption('accounts', null, InputOption::VALUE_OPTIONAL, 'Список ID счетов через запятую');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getArgument('companyId');

        $from = $this->parseDateOption((string) $input->getOption('from'));
        if (false === $from) {
            $output->writeln('<error>Опция --from должна быть в формате YYYY-MM-DD.</error>');

            return Command::FAILURE;
        }

        $to = $this->parseDateOption((string) $input->getOption('to'));
        if (false === $to) {
            $output->writeln('<error>Опция --to должна быть в формате YYYY-MM-DD.</error>');

            return Command::FAILURE;
        }

        $accountsOption = (string) $input->getOption('accounts');
        $accountIds = null;
        if ('' !== trim($accountsOption)) {
            $accountIds = array_values(array_filter(
                array_map('trim', explode(',', $accountsOption)),
                static fn (string $value): bool => '' !== $value,
            ));

            $invalidAccountIds = array_values(array_filter(
                $accountIds,
                static fn (string $value): bool => !Uuid::isValid($value),
            ));

            if ([] !== $invalidAccountIds) {
                $output->writeln(sprintf(
                    '<error>Опция --accounts должна содержать UUID. Некорректные значения: %s.</error>',
                    implode(', ', $invalidAccountIds),
                ));

                return Command::FAILURE;
            }
        }

        $this->bus->dispatch(new EnqueueAutoRulesForRange(
            $companyId,
            $from,
            $to,
            $accountIds,
        ));

        $output->writeln('<info>Сообщение поставлено в очередь.</info>');
        $output->writeln(sprintf('Компания: %s', $companyId));
        $output->writeln(sprintf('Диапазон дат: %s — %s', $from?->format('Y-m-d') ?? 'не задан', $to?->format('Y-m-d') ?? 'не задан'));
        $output->writeln(sprintf('Счета: %s', $accountIds ? implode(', ', $accountIds) : 'все счета'));
        $output->writeln('Следите за прогрессом: php bin/console messenger:consume async -vv');

        return Command::SUCCESS;
    }

    private function parseDateOption(string $value): \DateTimeImmutable|false|null
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return false;
        }
    }
}
