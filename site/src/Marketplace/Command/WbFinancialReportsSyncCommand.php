<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Enum\FinancialReportSyncMode;
use DateTimeImmutable;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketplace:wb-financial-reports:sync',
    description: 'Планировщик синхронизации финансовых отчётов WB (daily/initial/refresh14/missing)',
)]
final class WbFinancialReportsSyncCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly WbFinancialReportSyncPlannerInterface $planner,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'all|initial|daily|refresh14|missing', 'all')
            ->addOption('company-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('connection-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Дата для single-day запуска (Y-m-d)')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Начало диапазона (Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Конец диапазона (Y-m-d)')
            ->addOption('force', null, InputOption::VALUE_NONE)
            ->addOption('max-days', null, InputOption::VALUE_OPTIONAL, 'Лимит missing-задач на connection', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        try {
            $mode = $this->resolveMode((string) $input->getOption('mode'));
            $companyId = $this->normalizeOptional((string) $input->getOption('company-id'));
            $connectionId = $this->normalizeOptional((string) $input->getOption('connection-id'));
            $force = (bool) $input->getOption('force');
            $maxDays = $this->parseMaxDays((string) $input->getOption('max-days'));
            [$from, $to] = $this->resolveRange($input);

            if ('all' === $mode && null !== $from) {
                throw new DomainException('Use explicit --mode when --date or --from/--to is provided.');
            }

            if ('missing' === $mode && null !== $from) {
                throw new DomainException('Mode missing does not support --date or --from/--to. Use --max-days instead.');
            }

            $modesToRun = 'all' === $mode
                ? ['initial', 'daily', 'refresh14', 'missing']
                : [$mode];

            $totalDispatched = 0;
            $criticalErrors = [];

            foreach ($modesToRun as $modeName) {
                try {
                    $dispatched = $this->runMode($modeName, $companyId, $connectionId, $force, $maxDays, $from, $to);
                    $totalDispatched += $dispatched;
                    $io->writeln(sprintf('Mode <info>%s</info>: dispatched <comment>%d</comment>', $modeName, $dispatched));
                } catch (\Throwable $e) {
                    $criticalErrors[] = sprintf('%s: %s', $modeName, $e->getMessage());
                    $this->logger->error('WB financial report planning mode failed.', [
                        'mode' => $modeName,
                        'company_id' => $companyId,
                        'connection_id' => $connectionId,
                        'exception' => $e,
                    ]);
                    $io->warning(sprintf('Mode %s failed: %s', $modeName, $e->getMessage()));
                }
            }

            $io->success(sprintf('Планирование завершено. Отправлено задач: %d', $totalDispatched));

            if ([] !== $criticalErrors) {
                $io->error('Критичные ошибки планирования: '.implode('; ', $criticalErrors));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            $this->logger->error('WB financial report planner command failed due to configuration error.', [
                'exception' => $e,
            ]);

            return Command::FAILURE;
        } finally {
            $this->release();
        }
    }

    private function runMode(string $mode, ?string $companyId, ?string $connectionId, bool $force, int $maxDays, ?DateTimeImmutable $from, ?DateTimeImmutable $to): int
    {
        return match ($mode) {
            'daily' => null !== $from
                ? $this->planner->planRange($from, $to ?? $from, FinancialReportSyncMode::DAILY, $companyId, $connectionId, $force)
                : $this->planner->planDaily($companyId, $connectionId, $force),
            'initial' => null !== $from
                ? $this->planner->planRange($from, $to ?? $from, FinancialReportSyncMode::INITIAL, $companyId, $connectionId, $force)
                : $this->planner->planInitial($companyId, $connectionId),
            'refresh14' => null !== $from
                ? $this->planner->planRange($from, $to ?? $from, FinancialReportSyncMode::REFRESH_14D, $companyId, $connectionId, true)
                : $this->planner->planRefresh14Days($companyId, $connectionId),
            'missing' => $this->planner->planMissing($companyId, $connectionId, $maxDays),
            default => throw new DomainException(sprintf('Unsupported mode: %s', $mode)),
        };
    }

    private function resolveMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!\in_array($normalized, ['all', 'initial', 'daily', 'refresh14', 'missing'], true)) {
            throw new DomainException('Invalid --mode. Allowed: all|initial|daily|refresh14|missing');
        }

        return $normalized;
    }

    private function parseMaxDays(string $raw): int
    {
        $maxDays = (int) $raw;
        if ($maxDays <= 0) {
            throw new DomainException('Option --max-days must be a positive integer.');
        }

        return $maxDays;
    }

    private function resolveRange(InputInterface $input): array
    {
        $date = $this->normalizeOptional((string) $input->getOption('date'));
        $from = $this->normalizeOptional((string) $input->getOption('from'));
        $to = $this->normalizeOptional((string) $input->getOption('to'));

        if (null !== $date && (null !== $from || null !== $to)) {
            throw new DomainException('Use either --date or --from/--to, not both.');
        }

        if (null !== $date) {
            $day = $this->periodResolver->normalizeBusinessDate($date);

            return [$day, $day];
        }

        if (null === $from && null === $to) {
            return [null, null];
        }

        if (null === $from || null === $to) {
            throw new DomainException('Both --from and --to are required for range planning.');
        }

        $fromDate = $this->periodResolver->normalizeBusinessDate($from);
        $toDate = $this->periodResolver->normalizeBusinessDate($to);

        if ($fromDate > $toDate) {
            throw new DomainException('Option --from must be less than or equal to --to.');
        }

        return [$fromDate, $toDate];
    }

    private function normalizeOptional(string $value): ?string
    {
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
