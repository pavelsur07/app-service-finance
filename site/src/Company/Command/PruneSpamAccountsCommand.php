<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\DTO\SpamAccountScan;
use App\Company\Infrastructure\Persistence\SpamAccountPurger;
use App\Company\Infrastructure\Query\SpamAccountCandidatesQuery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Удаление спам-аккаунтов окна регистрации вместе с их пустыми кабинетами.
 * Без --execute — только отчёт. Адреса почты и названия кабинетов не выводятся.
 */
#[AsCommand(
    name: 'app:company:prune-spam-accounts',
    description: 'Удаляет аккаунты окна регистрации без данных, кроме автосида кабинета (по умолчанию dry-run)',
)]
final class PruneSpamAccountsCommand extends Command
{
    private const BATCH = 100;

    public function __construct(
        private readonly SpamAccountCandidatesQuery $candidates,
        private readonly SpamAccountPurger $purger,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('registered-from', null, InputOption::VALUE_REQUIRED, 'Начало окна регистрации, YYYY-MM-DD (включительно, время БД)')
            ->addOption('registered-to', null, InputOption::VALUE_REQUIRED, 'Конец окна регистрации, YYYY-MM-DD (исключительно)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Удалить найденные аккаунты; без флага — только отчёт')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число кандидатов из dry-run; обязательно с --execute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = $this->parseDate($input->getOption('registered-from'));
        $to = $this->parseDate($input->getOption('registered-to'));
        if (null === $from || null === $to || $from >= $to) {
            $io->error('--registered-from и --registered-to обязательны: даты YYYY-MM-DD, from < to.');

            return Command::INVALID;
        }

        $execute = (bool) $input->getOption('execute');
        $expected = $input->getOption('expected-count');
        if ($execute && (!\is_string($expected) || 1 !== preg_match('/^\d+$/', $expected))) {
            $io->error('--execute требует --expected-count=<число кандидатов из dry-run>.');

            return Command::INVALID;
        }

        $io->title($execute ? 'Удаление спам-аккаунтов' : 'Спам-аккаунты (dry-run)');
        $scan = $this->candidates->scan($from, $to);
        $this->report($io, $from, $to, $scan);

        if (!$execute) {
            $io->note('Dry-run: данные не изменены.');

            return Command::SUCCESS;
        }

        if ((int) $expected !== \count($scan->candidates)) {
            $io->error(\sprintf('Кандидатов %d, ожидалось %s — данные изменились, повторите dry-run.', \count($scan->candidates), $expected));

            return Command::INVALID;
        }

        $context = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'candidates' => \count($scan->candidates)];
        $this->logger->info('Spam accounts prune started', $context);

        $total = [];
        foreach (array_chunk($scan->candidates, self::BATCH) as $batch) {
            $deleted = $this->connection->transactional(fn (): array => $this->purger->purge($batch));
            foreach ($deleted as $table => $count) {
                $total[$table] = ($total[$table] ?? 0) + $count;
            }
        }

        $io->section('Удалено строк');
        $io->table(['Таблица', 'Строк'], array_map(null, array_keys($total), array_values($total)));

        $after = $this->candidates->scan($from, $to);
        $io->definitionList(
            ['Осталось пользователей в окне' => $after->usersInWindow],
            ['Осталось кандидатов' => \count($after->candidates)],
        );

        $this->logger->info('Spam accounts prune finished', $context + ['deleted' => $total, 'remaining_candidates' => \count($after->candidates)]);
        $io->success('Готово.');

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, \DateTimeImmutable $from, \DateTimeImmutable $to, SpamAccountScan $scan): void
    {
        $io->definitionList(
            ['Окно регистрации' => \sprintf('[%s, %s)', $from->format('Y-m-d'), $to->format('Y-m-d'))],
            ['Пользователей в окне' => $scan->usersInWindow],
            ['Кандидатов на удаление' => \count($scan->candidates)],
            ['  их кабинетов' => $scan->companyCount()],
            ['Отсеяно' => array_sum($scan->rejectedByReason)],
        );

        if ([] !== $scan->rejectedByReason) {
            $io->section('Отсеяно по причине');
            $io->table(['Причина', 'Пользователей'], array_map(null, array_keys($scan->rejectedByReason), array_values($scan->rejectedByReason)));
        }
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
