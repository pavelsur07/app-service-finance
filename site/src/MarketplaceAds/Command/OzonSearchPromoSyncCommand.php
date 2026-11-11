<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Application\SyncOzonSearchPromoSpendAction;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * Оплата за заказ Ozon по SKU: отчёты по заказам, загруженные Ingestion (cron 07:25), — в рекламную
 * статистику всех кабинетов с Ozon Performance. Без дат — месяц вчерашнего дня по вчера (1-го числа —
 * весь прошлый месяц, последнее обновление месяца).
 */
#[AsCommand(
    name: 'app:marketplace-ads:ozon-search-promo:sync',
    description: 'Projects stored Ozon pay-per-order (Search Promo) orders reports into ad statistics by SKU.',
)]
final class OzonSearchPromoSyncCommand extends Command
{
    use LockableTrait;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly SyncOzonSearchPromoSpendAction $syncAction,
        private readonly ActiveOzonPerformanceConnectionsQuery $connectionsQuery,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Only this company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day, YYYY-MM-DD (with --to).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day, YYYY-MM-DD (with --from).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            [$companyIds, $from, $to] = $this->scope($input);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::INVALID;
        }

        if (!$this->lock()) {
            $output->writeln('<comment>Another Ozon pay-per-order sync is running, skipping.</comment>');

            return self::SUCCESS;
        }

        try {
            $failed = 0;
            foreach ($companyIds as $companyId) {
                $context = ['companyId' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
                $this->logger->info('Ozon pay-per-order sync started.', $context);
                try {
                    $result = ($this->syncAction)($companyId, $from, $to);
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->logger->error('Ozon pay-per-order sync failed.', $context + ['exception' => $exception]);
                    $output->writeln(sprintf('<error>%s: %s</error>', $companyId, $exception->getMessage()));
                    $manager = $this->managerRegistry->getManager();
                    if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                        $this->managerRegistry->resetManager();
                    }
                    continue;
                }
                $this->logger->info('Ozon pay-per-order sync finished.', $context + [
                    'days' => $result->days,
                    'rows' => $result->rows,
                    'total' => $result->total,
                    'mismatchedSku' => $result->mismatchedSku,
                    'unreadable' => $result->unreadable,
                    'daysWithUnmappedSku' => $result->daysWithUnmappedSku,
                ]);
                $output->writeln(sprintf(
                    '%s: дней записано %d, строк %d, расход %s, заказанный SKU ≠ продвигаемому %d, дней с SKU без листинга %d, нечитаемых строк сырья %d',
                    $companyId,
                    $result->days,
                    $result->rows,
                    $result->total,
                    $result->mismatchedSku,
                    $result->daysWithUnmappedSku,
                    $result->unreadable,
                ));
            }

            return 0 === $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->release();
        }
    }

    /**
     * @return array{0: list<string>, 1: \DateTimeImmutable, 2: \DateTimeImmutable}
     */
    private function scope(InputInterface $input): array
    {
        $companyId = $input->getOption('company-id');
        if (null !== $companyId) {
            Assert::uuid($companyId, 'Invalid --company-id.');
        }

        $fromOption = $input->getOption('from');
        $toOption = $input->getOption('to');
        if (null === $fromOption && null === $toOption) {
            $yesterday = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0)->modify('-1 day');
            $from = $yesterday->modify('first day of this month');
            $to = $yesterday;
        } else {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $fromOption);
            $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $toOption);
            Assert::isInstanceOf($from, \DateTimeImmutable::class, 'Invalid --from, expected YYYY-MM-DD.');
            Assert::isInstanceOf($to, \DateTimeImmutable::class, 'Invalid --to, expected YYYY-MM-DD.');
            Assert::true($from <= $to && $from->modify('+366 days') > $to, 'Period must be 1..366 days.');
        }

        $companyIds = null !== $companyId
            ? [(string) $companyId]
            : array_values(array_unique($this->connectionsQuery->getCompanyIds()));

        return [$companyIds, $from, $to];
    }
}
