<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Service\OzonRawDuplicatesCleanupExecutor;
use App\Marketplace\Application\Service\OzonRawDuplicatesCleanupPlanner;
use App\Marketplace\Message\ProcessDayReportMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:marketplace:ozon-raw-duplicates-cleanup',
    description: 'Prod-safe cleanup дублей Ozon raw/processed данных (dry-run by default).',
)]
final class OzonRawDuplicatesCleanupCommand extends Command
{
    public function __construct(
        private readonly OzonRawDuplicatesCleanupPlanner $planner,
        private readonly OzonRawDuplicatesCleanupExecutor $executor,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании (required)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Начало периода (YYYY-MM-DD, required)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Конец периода (YYYY-MM-DD, required)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Применить cleanup (без флага работает только dry-run)')
            ->addOption('dispatch-reprocess', null, InputOption::VALUE_NONE, 'После apply dispatch ProcessDayReportMessage по canonical rawDoc');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = (string) $input->getOption('company-id');
        $fromOption = (string) $input->getOption('from');
        $toOption = (string) $input->getOption('to');
        $apply = (bool) $input->getOption('apply');
        $dispatchReprocess = (bool) $input->getOption('dispatch-reprocess');

        if ($companyId === '' || $fromOption === '' || $toOption === '') {
            $io->error('Опции --company-id, --from и --to обязательны.');

            return Command::FAILURE;
        }

        try {
            Assert::uuid($companyId);
        } catch (\InvalidArgumentException) {
            $io->error('Некорректный UUID в --company-id.');

            return Command::FAILURE;
        }

        $from = $this->parseStrictDate($fromOption);
        $to = $this->parseStrictDate($toOption);

        if ($from === null || $to === null) {
            $io->error('Неверный формат дат. Используйте YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($from > $to) {
            $io->error('Опция --from должна быть меньше или равна --to.');

            return Command::FAILURE;
        }

        $plan = $this->planner->buildPlan($companyId, $from, $to);

        $io->title('Ozon raw duplicates cleanup plan');
        $io->definitionList(
            ['Mode' => $apply ? 'APPLY' : 'DRY-RUN'],
            ['Company ID' => $companyId],
            ['From' => $from->format('Y-m-d')],
            ['To' => $to->format('Y-m-d')],
            ['Affected days' => (string) count($plan->affectedDays)],
        );

        foreach ($plan->affectedDays as $dayPlan) {
            $io->section($dayPlan->day->format('Y-m-d'));
            $io->listing([
                sprintf('canonicalRawDocumentId: %s', $dayPlan->canonicalRawDocumentId),
                sprintf('duplicateRawDocumentIds: %s', $dayPlan->duplicateRawDocumentIds === [] ? '-' : implode(', ', $dayPlan->duplicateRawDocumentIds)),
                sprintf('stale open rows (sales/returns/costs): %d / %d / %d', $dayPlan->staleSalesRowsCount, $dayPlan->staleReturnsRowsCount, $dayPlan->staleCostsRowsCount),
                sprintf('closed rows (sales/returns/costs): %d / %d / %d', $dayPlan->closedSalesRowsCount, $dayPlan->closedReturnsRowsCount, $dayPlan->closedCostsRowsCount),
                sprintf('canAutoCleanup: %s', $dayPlan->canAutoCleanup ? 'true' : 'false'),
                sprintf('safe_to_delete_raw_docs (info only): %s', $dayPlan->safeToDeleteRawDocumentIds === [] ? '-' : implode(', ', $dayPlan->safeToDeleteRawDocumentIds)),
            ]);

            foreach ($dayPlan->warnings as $warning) {
                $io->warning($warning);
            }
        }

        $daysBlocked = array_filter($plan->affectedDays, static fn ($dayPlan): bool => !$dayPlan->canAutoCleanup);

        $io->section('Summary');
        $io->listing([
            sprintf('days affected: %d', count($plan->affectedDays)),
            sprintf('open rows to delete (sales/returns/costs): %d / %d / %d', $plan->totalStaleSalesRows(), $plan->totalStaleReturnsRows(), $plan->totalStaleCostsRows()),
            sprintf('days blocked from auto-cleanup: %d', count($daysBlocked)),
        ]);

        if (!$apply) {
            $io->success('Dry-run completed. Изменений в БД не выполнено. Добавьте --apply для применения cleanup.');

            return Command::SUCCESS;
        }

        $result = $this->executor->execute($plan);

        if ($dispatchReprocess) {
            foreach ($result->cleanedCanonicalRawDocumentIds as $canonicalRawDocumentId) {
                $this->messageBus->dispatch(new ProcessDayReportMessage($companyId, $canonicalRawDocumentId));
            }
        }

        $io->success('Cleanup applied.');
        $io->listing([
            sprintf('deleted rows (sales/returns/costs): %d / %d / %d', $result->deletedSalesRows, $result->deletedReturnsRows, $result->deletedCostsRows),
            sprintf('cleaned days: %d', $result->cleanedDaysCount),
            sprintf('reprocess messages dispatched: %d', $dispatchReprocess ? count($result->cleanedCanonicalRawDocumentIds) : 0),
            sprintf('canonical raw docs: %s', $result->cleanedCanonicalRawDocumentIds === [] ? '-' : implode(', ', $result->cleanedCanonicalRawDocumentIds)),
        ]);

        if (!$dispatchReprocess && $result->cleanedCanonicalRawDocumentIds !== []) {
            $io->note('Для ручного reprocess используйте canonical raw docs из списка выше.');
        }

        return Command::SUCCESS;
    }

    private function parseStrictDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
