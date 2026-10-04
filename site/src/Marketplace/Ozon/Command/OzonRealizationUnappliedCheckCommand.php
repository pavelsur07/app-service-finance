<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Ozon\Application\Realization\OzonRealizationCatchupWindow;
use App\Marketplace\Ozon\Infrastructure\Query\OzonUnappliedRealizationQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт: «Реализация» Ozon, загруженная больше суток назад, обязана быть применена в учёт. Read-only.
 *
 * Стоит после ночного догоняющего прохода (`app:marketplace:ozon-realization-catchup`): если документ всё ещё не применён, значит
 * обработка не получается (сбой действия, нет шага) — это чинится разбором причины или кнопкой «Применить выручку».
 * Охват гейта равен охвату починки (`docs/workflow/health-gates.md`): последние N закрытых месяцев, как у прохода; закрытый месяц
 * (`conflict`) гейт не краснит — документ применять нельзя, это решение человека; месяцы старше окна не проверяются.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-realization-unapplied-check',
    description: 'Гейт: «Реализация» Ozon, загруженная больше суток назад, применена в учёт (последние 3 месяца)',
)]
final class OzonRealizationUnappliedCheckCommand extends Command
{
    private const GRACE = 'PT24H';
    private const STUCK_AFTER = 'PT1H';
    private const MAX_LOGGED = 20;

    public function __construct(
        private readonly OzonUnappliedRealizationQuery $query,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('report-only', null, InputOption::VALUE_NONE, 'Показать итог, не падать и не писать error');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reportOnly = (bool) $input->getOption('report-only');
        $now = $this->clock->now();
        $window = OzonRealizationCatchupWindow::at($now);

        $output->writeln(sprintf('start: months %s .. %s', $window->monthFrom->format('Y-m'), $window->monthTo->format('Y-m')));

        $documents = $this->query->find(
            $window->monthFrom,
            $window->monthTo,
            $now,
            new \DateInterval(self::STUCK_AFTER),
            $now->sub(new \DateInterval(self::GRACE)),
        );

        $states = [];
        $companies = [];
        foreach ($documents as $document) {
            $state = $document['status'] ?? 'no_pair';
            $states[$state] = ($states[$state] ?? 0) + 1;
            $companies[$document['companyId']] = true;
            $output->writeln(sprintf('UNAPPLIED company %s %04d-%02d document %s (%s)', $document['companyId'], $document['year'], $document['month'], $document['documentId'], $state));
        }

        $output->writeln(sprintf('unapplied count: %d', count($documents)));
        $output->writeln('finish');

        if ([] === $documents || $reportOnly) {
            return self::SUCCESS;
        }

        // Один агрегированный error на запуск: человека будят один раз, а не по документу.
        $this->logger->error('Ozon realization documents are loaded but not applied.', [
            'unapplied' => count($documents),
            'companies' => count($companies),
            'company_ids' => array_slice(array_keys($companies), 0, self::MAX_LOGGED),
            'states' => $states,
        ]);

        return self::FAILURE;
    }
}
