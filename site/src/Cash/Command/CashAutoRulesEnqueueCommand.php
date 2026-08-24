<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Company\Repository\UserRepository;
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
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Начальная дата (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Конечная дата (YYYY-MM-DD)')
            ->addOption('accounts', null, InputOption::VALUE_OPTIONAL, 'Список ID счетов через запятую')
            ->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'Режим: safe или replace_auto_assigned', 'safe')
            ->addOption('actor-user-id', null, InputOption::VALUE_OPTIONAL, 'UUID пользователя для небезопасного режима')
            ->addOption('confirm-replace', null, InputOption::VALUE_NONE, 'Подтвердить небезопасную замену');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getArgument('companyId');
        if (!Uuid::isValid($companyId)) {
            $output->writeln('<error>Аргумент companyId должен содержать UUID.</error>');

            return Command::FAILURE;
        }

        $mode = CashTransactionAutoRuleApplyMode::tryFrom((string) $input->getOption('mode'));
        if (null === $mode) {
            $output->writeln('<error>Опция --mode должна быть safe или replace_auto_assigned.</error>');

            return Command::FAILURE;
        }

        $initiatedByUserId = trim((string) $input->getOption('actor-user-id')) ?: null;
        if (null !== $initiatedByUserId && !Uuid::isValid($initiatedByUserId)) {
            $output->writeln('<error>Опция --actor-user-id должна содержать UUID.</error>');

            return Command::FAILURE;
        }
        if ($mode->replacesAutoAssigned()
            && (!(bool) $input->getOption('confirm-replace') || null === $initiatedByUserId)) {
            $output->writeln('<error>Для replace_auto_assigned нужны --confirm-replace и корректный --actor-user-id.</error>');

            return Command::FAILURE;
        }
        if (null !== $initiatedByUserId
            && null === $this->userRepository->findOneByIdAndCompanyId($initiatedByUserId, $companyId)) {
            $output->writeln('<error>Пользователь из --actor-user-id не принадлежит указанной компании.</error>');

            return Command::FAILURE;
        }

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
            Uuid::uuid7()->toString(),
            $mode,
            $initiatedByUserId,
        ));

        $output->writeln('<info>Сообщение поставлено в очередь.</info>');
        $output->writeln(sprintf('Компания: %s', $companyId));
        $output->writeln(sprintf('Диапазон дат: %s — %s', $from?->format('Y-m-d') ?? 'не задан', $to?->format('Y-m-d') ?? 'не задан'));
        $output->writeln(sprintf('Счета: %s', $accountIds ? implode(', ', $accountIds) : 'все счета'));
        $output->writeln(sprintf('Режим: %s', $mode->value));
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
