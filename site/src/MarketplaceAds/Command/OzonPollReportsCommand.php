<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Application\DTO\PollResult;
use App\MarketplaceAds\Application\Service\OzonAdReportPoller;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * CLI-команда shared-polling для Ozon Performance /statistics/list.
 *
 * Проходит по всем компаниям, у которых есть хоть одна due in-flight запись,
 * и делает ровно один GET /statistics/list на компанию (через {@see OzonAdReportPoller}).
 *
 * В step 3/5 async-poll redesign команда ещё не включена в cron — прод
 * запускает её вручную для валидации. Step 5 добавит её в docker/cron/app.cron.
 *
 * Опции:
 *  - --company-id  только эта компания (UUID)
 *  - --dry-run     ничего не трогает: печатает, кого бы опросил
 *
 * Exit code: SUCCESS если прошло без ошибок, FAILURE если хоть одна компания
 * не опросилась — чтобы будущая cron-обвязка и алерт-подписчики видели факт.
 */
#[AsCommand(
    name: 'app:marketplace-ads:ozon-poll-reports',
    description: 'Poll Ozon Performance /statistics/list for all in-flight reports across companies.',
)]
final class OzonPollReportsCommand extends Command
{
    public function __construct(
        private readonly OzonAdPendingReportRepository $repo,
        private readonly OzonAdReportPoller $poller,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'company-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Poll only this company (UUID). Default: all companies with due reports.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print what would be polled, do not call Ozon or write to DB.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $onlyCompanyId = $input->getOption('company-id');
        $dryRun = (bool) $input->getOption('dry-run');

        $companyIds = null !== $onlyCompanyId
            ? [(string) $onlyCompanyId]
            : $this->repo->findCompanyIdsWithDueReports($now);

        if ([] === $companyIds) {
            $output->writeln('<info>No companies with due reports.</info>');

            return Command::SUCCESS;
        }

        $totals = new PollResult(0, 0, 0, 0);

        foreach ($companyIds as $companyId) {
            if ($dryRun) {
                $inFlight = $this->repo->findInFlightByCompany($companyId);
                $output->writeln(sprintf(
                    '<comment>DRY</comment> company=%s in_flight=%d',
                    $companyId,
                    count($inFlight),
                ));
                continue;
            }

            try {
                $result = ($this->poller)($companyId, $now);
            } catch (\Throwable $e) {
                // Per-company isolation: сбой одной компании не должен
                // блокировать остальные.
                $this->logger->error('Ozon poll failed for company', [
                    'companyId' => $companyId,
                    'error' => $e->getMessage(),
                ]);
                $result = new PollResult(seen: 0, updated: 0, finalized: 0, errors: 1);
            }

            $output->writeln(sprintf(
                'company=%s seen=%d updated=%d finalized=%d errors=%d',
                $companyId,
                $result->seen,
                $result->updated,
                $result->finalized,
                $result->errors,
            ));

            $totals = $totals->merge($result);
        }

        $output->writeln(sprintf(
            '<info>Totals: companies=%d seen=%d updated=%d finalized=%d errors=%d</info>',
            count($companyIds),
            $totals->seen,
            $totals->updated,
            $totals->finalized,
            $totals->errors,
        ));

        return $totals->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
