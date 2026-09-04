<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Application\LoadWbAdSpendDayActionInterface;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\WildberriesAdRateLimitException;
use App\MarketplaceAds\Exception\WildberriesAdTransientException;
use App\Shared\Service\AppLogger;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:marketplace-ads:wb-daily-spend',
    description: 'Loads completed-day Wildberries advertising expense and allocates it by nmId.',
)]
final class WbAdDailySpendCommand extends Command
{
    use LockableTrait;

    private const TIMEZONE = 'Europe/Moscow';
    private const REVIEW_SAMPLE_LIMIT = 10;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly LoadWbAdSpendDayActionInterface $loadAction,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
        private readonly AppLogger $appLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Completed report date, YYYY-MM-DD. Defaults to yesterday in Europe/Moscow.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('connection-id', null, InputOption::VALUE_REQUIRED, 'Optional WB seller connection UUID filter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Another WB ad spend load is running, skipping.</comment>');

            return self::SUCCESS;
        }

        try {
            $date = $this->reportDate($input, $output);
            if (null === $date) {
                return self::INVALID;
            }

            $companyId = $this->uuidOption($input, $output, 'company-id');
            if (false === $companyId) {
                return self::INVALID;
            }
            $connectionId = $this->uuidOption($input, $output, 'connection-id');
            if (false === $connectionId) {
                return self::INVALID;
            }

            $connections = $this->marketplaceFacade->getActiveWbSellerConnections($companyId);
            if (null !== $connectionId) {
                $connections = array_values(array_filter(
                    $connections,
                    static fn (array $row): bool => $connectionId === $row['connectionId'],
                ));
            }

            if ([] === $connections) {
                $output->writeln('<info>No matching active Wildberries seller connections.</info>');

                return self::SUCCESS;
            }

            $loaded = 0;
            $reviewRequired = 0;
            $failed = 0;
            $incidentFailures = [];
            $reviewSample = [];

            foreach ($connections as $connection) {
                $currentCompanyId = $connection['companyId'];
                $currentConnectionId = $connection['connectionId'];

                try {
                    $result = ($this->loadAction)(
                        $currentCompanyId,
                        $currentConnectionId,
                        $date,
                    );
                    ++$loaded;
                    if (AdRawDocumentStatus::DRAFT === $result->status) {
                        ++$reviewRequired;
                        if (count($reviewSample) < self::REVIEW_SAMPLE_LIMIT) {
                            $reviewSample[] = [
                                'companyId' => $currentCompanyId,
                                'connectionId' => $currentConnectionId,
                                'rawDocumentId' => $result->rawDocumentId,
                                'unmappedTotal' => $result->unmappedTotal,
                                'unmappedCount' => $result->unmappedCount,
                                'catalogRefreshAttempted' => $result->catalogRefreshAttempted,
                                'catalogRefreshed' => $result->catalogRefreshed,
                                'projectionRetryCount' => $result->projectionRetryCount,
                            ];
                        }
                    }

                    // `total` is retained as a compatibility alias for the
                    // explicit `/upd`-derived `source` field.
                    $output->writeln(sprintf(
                        'company=%s connection=%s status=%s campaigns=%d sku=%d attributed=%s unallocated=%s persisted_unallocated=%s total=%s source=%s documents=%s lines=%s without_lines=%s unmapped=%s unmapped_count=%d reconciled=%s catalog_refresh_attempted=%s catalog_refreshed=%s projection_retries=%d',
                        $currentCompanyId,
                        $currentConnectionId,
                        $result->status->value,
                        $result->campaignCount,
                        $result->skuCount,
                        $result->attributedTotal,
                        $result->unallocatedTotal,
                        $result->persistedUnallocatedTotal,
                        $result->actualTotal,
                        $result->actualTotal,
                        $result->documentTotal,
                        $result->lineTotal,
                        $result->withoutLineTotal,
                        $result->unmappedTotal,
                        $result->unmappedCount,
                        $result->reconciled ? 'yes' : 'no',
                        $result->catalogRefreshAttempted ? 'yes' : 'no',
                        $result->catalogRefreshed ? 'yes' : 'no',
                        $result->projectionRetryCount,
                    ));
                } catch (\Throwable $exception) {
                    ++$failed;
                    // Rate-limit и сетевые сбои после ретраев ожидаемы и лечатся повторным
                    // запуском с --date; error оставлен для auth, сверки и неизвестного.
                    // Детали каждого сбоя — warning; инцидент-уровень — один
                    // агрегированный error после цикла, а не по подключению.
                    $failureContext = [
                        'companyId' => $currentCompanyId,
                        'connectionId' => $currentConnectionId,
                        'date' => $date->format('Y-m-d'),
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ];
                    $this->logger->warning('WB daily ad spend connection failed.', $failureContext);
                    if (!$exception instanceof WildberriesAdRateLimitException && !$exception instanceof WildberriesAdTransientException) {
                        $incidentFailures[] = $failureContext;
                    }
                    $output->writeln(sprintf(
                        '<error>company=%s connection=%s failed: %s</error>',
                        $currentCompanyId,
                        $currentConnectionId,
                        $exception->getMessage(),
                    ));
                }
            }

            if ([] !== $incidentFailures) {
                $this->logger->error('WB daily ad spend connections failed.', [
                    'event' => 'wb_ad_spend_connections_failed',
                    'date' => $date->format('Y-m-d'),
                    'failedCount' => count($incidentFailures),
                    'failures' => $incidentFailures,
                ]);
            }

            if ($reviewRequired > 0) {
                $this->appLogger->error('WB daily ad spend review required.', context: [
                    'event' => 'wb_ad_spend_review_required',
                    'date' => $date->format('Y-m-d'),
                    'reviewRequired' => $reviewRequired,
                    'sample' => $reviewSample,
                ]);
            }

            $output->writeln(sprintf(
                '<info>WB ad spend date=%s loaded=%d review_required=%d failed=%d</info>',
                $date->format('Y-m-d'),
                $loaded,
                $reviewRequired,
                $failed,
            ));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $this->release();
        }
    }

    private function reportDate(InputInterface $input, OutputInterface $output): ?\DateTimeImmutable
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $today = $this->clock->now()->setTimezone($timezone)->setTime(0, 0);
        $value = trim((string) $input->getOption('date'));

        if ('' === $value) {
            return $today->modify('-1 day');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $output->writeln('<error>Invalid --date; expected YYYY-MM-DD.</error>');

            return null;
        }
        if ($date >= $today) {
            $output->writeln('<error>--date must be a completed day before today in Europe/Moscow.</error>');

            return null;
        }

        return $date;
    }

    /**
     * @return string|false|null false means invalid
     */
    private function uuidOption(
        InputInterface $input,
        OutputInterface $output,
        string $name,
    ): string|false|null {
        $value = trim((string) $input->getOption($name));
        if ('' === $value) {
            return null;
        }
        if (!Uuid::isValid($value)) {
            $output->writeln(sprintf('<error>Invalid --%s; expected UUID.</error>', $name));

            return false;
        }

        return $value;
    }
}
