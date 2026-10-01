<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Exception\InvalidReconciliationPeriodException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручной запуск сверки с Ozon за месяц одной компании.
 *
 * Пишет только в таблицы снимков сверки; учёт и сырые данные не меняет.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-reconciliation:run',
    description: 'Сверка данных Ozon (Реализация ↔ сырьё ↔ учёт) за месяц',
)]
final class OzonReconciliationRunCommand extends Command
{
    private const TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly RunOzonReconciliationAction $action,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Месяц YYYY-MM (по умолчанию текущий, по Москве)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company-id');
        if (!is_string($companyId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)) {
            $io->error('Нужен --company-id=<uuid>.');

            return Command::INVALID;
        }

        $month = $input->getOption('month');
        $month = is_string($month) && '' !== $month
            ? $month
            : $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m');

        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
        if (false === $first || $first->format('Y-m') !== $month) {
            $io->error('Месяц задаётся как YYYY-MM.');

            return Command::INVALID;
        }

        try {
            $run = ($this->action)($companyId, $first, $first->modify('last day of this month'));
        } catch (InvalidReconciliationPeriodException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf(
            'Сверка %s: %s, расхождений: %d, дней сырья %d из %d, «Реализация»: %s.',
            $month,
            $run->getOverallStatus()->getLabel(),
            $run->getMismatchCount(),
            $run->getRawDaysPresent(),
            $run->getRawDaysExpected(),
            $run->hasRealization() ? 'есть' : 'нет',
        ));

        return Command::SUCCESS;
    }
}
