<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DispatchOzonAdLoadActionInterface;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:marketplace-ads:reconcile', description: 'Reconcile missed Ozon ad spend load dates')]
final class ReconcileMarketplaceAdsCommand extends Command
{
    public function __construct(
        private readonly AdLoadJobRepositoryInterface $jobRepository,
        private readonly DispatchOzonAdLoadActionInterface $dispatchOzonAdLoadAction,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED, 'Marketplace', 'ozon')
            ->addOption('company', null, InputOption::VALUE_REQUIRED)
            ->addOption('from', null, InputOption::VALUE_REQUIRED)
            ->addOption('to', null, InputOption::VALUE_REQUIRED)
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
            ->addOption('include-failed', null, InputOption::VALUE_NONE)
            ->addOption('include-rate-limited', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $marketplace = (string) $input->getOption('marketplace');
        if ('ozon' !== strtolower($marketplace)) {
            $output->writeln('<error>Only --marketplace=ozon is supported.</error>');
            return self::INVALID;
        }

        $companyId = (string) $input->getOption('company');
        if ('' === trim($companyId)) {
            $output->writeln('<error>Option --company is required.</error>');
            return self::INVALID;
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('to'));
        if (false === $from || false === $to || $from > $to) {
            $output->writeln('<error>Invalid --from/--to range.</error>');
            return self::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $includeFailed = (bool) $input->getOption('include-failed');
        $includeRateLimited = (bool) $input->getOption('include-rate-limited');

        $created = 0;
        $wouldCreate = 0;
        $skipped = 0;
        $deferred = 0;
        $failed = 0;
        $createdInThisRun = false;
        $stopDispatchInThisRun = false;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            try {
                $active = $this->jobRepository->findActiveJobCoveringDate($companyId, MarketplaceType::OZON, $day);
                if (null !== $active) {
                    ++$deferred;
                    $output->writeln(sprintf('%s deferred: active pipeline already running for this date', $day->format('Y-m-d')));
                    continue;
                }

                $completed = $this->jobRepository->findCompletedJobCoveringDate($companyId, MarketplaceType::OZON, $day);
                if (null !== $completed) {
                    ++$skipped;
                    $output->writeln(sprintf('%s skip: completed', $day->format('Y-m-d')));
                    continue;
                }

                $latest = $this->jobRepository->findLatestJobCoveringDate($companyId, MarketplaceType::OZON, $day);
                $shouldReload = false;
                $reason = 'missing';
                if (null === $latest) {
                    $shouldReload = true;
                } elseif (AdLoadJobStatus::COMPLETED === $latest->getStatus()) {
                    $reason = 'completed';
                } elseif (AdLoadJobStatus::PARTIAL_SUCCESS === $latest->getStatus()) {
                    $reason = 'partial_success';
                    $shouldReload = $includeFailed;
                } elseif (AdLoadJobStatus::FAILED === $latest->getStatus()) {
                    $failure = (string) $latest->getFailureReason();
                    if (self::isRateLimitedFailure($failure)) {
                        $reason = 'rate_limited';
                        $shouldReload = $includeRateLimited || $includeFailed;
                        $this->logger->warning('Reconcile: rate-limited day detected', [
                            'companyId' => $companyId,
                            'jobId' => $latest->getId(),
                            'dateFrom' => $day->format('Y-m-d'),
                            'dateTo' => $day->format('Y-m-d'),
                        ]);
                    } elseif (self::isPermanentFailure($failure)) {
                        $reason = 'failed_permanent';
                    } else {
                        $reason = 'failed_transient';
                        $shouldReload = $includeFailed;
                    }
                } else {
                    $reason = $latest->getStatus()->value;
                }

                $output->writeln(sprintf('%s %s: %s', $day->format('Y-m-d'), $shouldReload ? 'reload' : 'skip', $reason));
                if ($shouldReload) {
                    ++$wouldCreate;
                    if ($createdInThisRun || $stopDispatchInThisRun) {
                        ++$deferred;
                        $output->writeln(sprintf('%s deferred: active job was created in this run; will retry on next reconcile run', $day->format('Y-m-d')));

                        continue;
                    }
                    if ($dryRun) {
                        continue;
                    }
                    ($this->dispatchOzonAdLoadAction)($companyId, $day, $day);
                    $createdInThisRun = true;
                    ++$created;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                if ($this->isFreshRateLimitedDispatchError($e)) {
                    $stopDispatchInThisRun = true;
                    ++$deferred;
                    $this->logger->warning('Reconcile: deferred due to fresh rate limit', [
                        'companyId' => $companyId,
                        'dateFrom' => $day->format('Y-m-d'),
                        'dateTo' => $day->format('Y-m-d'),
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]);
                    $output->writeln(sprintf('%s deferred: rate_limited', $day->format('Y-m-d')));

                    continue;
                }

                ++$failed;
                $this->logger->error('Reconcile: failed to process day', [
                    'companyId' => $companyId,
                    'dateFrom' => $day->format('Y-m-d'),
                    'dateTo' => $day->format('Y-m-d'),
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $output->writeln(sprintf('%s error: %s', $day->format('Y-m-d'), $e->getMessage()));
            }
        }

        $output->writeln(sprintf(
            'done: would_create=%d created=%d skipped=%d deferred=%d failed=%d remaining=%d dry_run=%s',
            $wouldCreate,
            $created,
            $skipped,
            $deferred,
            $failed,
            max(0, $wouldCreate - $created),
            $dryRun ? 'yes' : 'no',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private static function isPermanentFailure(string $failure): bool
    {
        $failure = mb_strtolower($failure);

        return str_contains($failure, '403')
            || str_contains($failure, '401')
            || str_contains($failure, 'invalid credentials');
    }

    private static function isRateLimitedFailure(string $failure): bool
    {
        $failure = mb_strtolower($failure);

        return str_contains($failure, '429')
            || str_contains($failure, 'ozonratelimitexception')
            || str_contains($failure, 'rate limit')
            || str_contains($failure, 'rate-limit')
            || str_contains($failure, 'marketplace api rate limit exceeded')
            || str_contains($failure, 'превышен лимит активных запросов')
            || str_contains($failure, 'лимит активных запросов');
    }

    private function isFreshRateLimitedDispatchError(\Throwable $e): bool
    {
        if ($e->getPrevious() instanceof OzonRateLimitException) {
            return true;
        }

        return self::isRateLimitedFailure($e->getMessage());
    }
}
