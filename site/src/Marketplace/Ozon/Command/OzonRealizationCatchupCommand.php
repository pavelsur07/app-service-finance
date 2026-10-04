<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationCatchupWindow;
use App\Marketplace\Ozon\Infrastructure\Query\OzonUnappliedRealizationQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Догоняющая обработка «Реализации» Ozon: ставит обработку для документов последних N закрытых месяцев,
 * которые загружены, но не применены в учёт (загружены прежним cron до автоматизации, обработка упала или «залипла»).
 *
 * Команда тонкая: решение о закрытых месяцах принимает обработчик (`ProcessOzonRealizationHandler`): окончательно закрытый этап
 * «Продажи и возвраты» → `conflict` без записи в учёт, предварительное закрытие и открытый месяц применяются; повтор идемпотентен.
 * Документы старше окна автоматически не применяются. За прогон — не больше `--limit` документов, новые месяцы первыми.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-realization-catchup',
    description: 'Поставить обработку «Реализации» Ozon для загруженных, но не применённых документов последних месяцев',
)]
final class OzonRealizationCatchupCommand extends Command
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 50;
    private const STUCK_AFTER = 'PT1H';

    public function __construct(
        private readonly OzonUnappliedRealizationQuery $query,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('months-back', null, InputOption::VALUE_REQUIRED, sprintf('Сколько закрытых месяцев назад смотреть (1..%d)', OzonRealizationCatchupWindow::MAX_MONTHS_BACK), (string) OzonRealizationCatchupWindow::DEFAULT_MONTHS_BACK)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Максимум документов за прогон (1..%d)', self::MAX_LIMIT), (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что было бы поставлено, ничего не ставя');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $monthsBack = $this->intOption($input, 'months-back', 1, OzonRealizationCatchupWindow::MAX_MONTHS_BACK);
        $limit = $this->intOption($input, 'limit', 1, self::MAX_LIMIT);
        if (null === $monthsBack || null === $limit) {
            $output->writeln(sprintf('--months-back: 1..%d, --limit: 1..%d', OzonRealizationCatchupWindow::MAX_MONTHS_BACK, self::MAX_LIMIT));

            return self::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $now = $this->clock->now();
        $window = OzonRealizationCatchupWindow::at($now, $monthsBack);
        $output->writeln(sprintf('start: months %s .. %s', $window->monthFrom->format('Y-m'), $window->monthTo->format('Y-m')));

        $documents = $this->query->find($window->monthFrom, $window->monthTo, $now, new \DateInterval(self::STUCK_AFTER), null, $limit);

        foreach ($documents as $document) {
            $output->writeln(sprintf(
                '%s company %s %04d-%02d document %s (%s)',
                $dryRun ? 'WOULD QUEUE' : 'QUEUED',
                $document['companyId'],
                $document['year'],
                $document['month'],
                $document['documentId'],
                $document['status'] ?? 'no_pair',
            ));

            if (!$dryRun) {
                $this->messageBus->dispatch(new ProcessOzonRealizationMessage($document['companyId'], $document['connectionId'], $document['documentId'], $document['year'], $document['month']));
            }
        }

        $output->writeln(sprintf('queued count: %d', count($documents)));
        $output->writeln('finish');

        $this->logger->info('Ozon realization catch-up finished', [
            'months_back' => $monthsBack,
            'limit' => $limit,
            'queued' => count($documents),
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): ?int
    {
        $raw = $input->getOption($name);
        if (!is_string($raw) || 1 !== preg_match('/^[1-9][0-9]{0,2}$/', $raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value >= $min && $value <= $max ? $value : null;
    }
}
