<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Сводка загруженного отчёта по заказам «Оплаты за заказ» Ozon по дням — для сверки с начислениями
 * Ozon (тип 54, «Маркетинговые акции»). Только чтение: номера заказов и названия не печатаются.
 *
 * Строка заказа — (ID заказа, SKU, Дата); при перекрытии окон берётся последняя загруженная версия.
 */
#[AsCommand(
    name: 'app:ingestion:ozon-performance:search-promo-orders-summary',
    description: 'Read-only daily summary of stored Ozon Search Promo (pay-per-order) orders reports.',
)]
final class OzonPerformanceSearchPromoOrdersSummaryCommand extends Command
{
    private const string ORDERS_EXTERNAL_ID_LIKE = 'performance-search-promo-stats:%:orders:%';

    public function __construct(
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day, YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day, YYYY-MM-DD.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = (string) $input->getOption('company-id');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('to'));
        try {
            Assert::uuid($companyId, 'Invalid --company-id.');
            Assert::isInstanceOf($from, \DateTimeImmutable::class, 'Invalid --from, expected YYYY-MM-DD.');
            Assert::isInstanceOf($to, \DateTimeImmutable::class, 'Invalid --to, expected YYYY-MM-DD.');
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $recordIds = $this->rawRecordRepository->findLatestIdsByCompanyAndResource(
            $companyId,
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            self::ORDERS_EXTERNAL_ID_LIKE,
        );

        // ponytail: строки всех отчётов кабинета в памяти — сотни объектов по ~20 КБ; при росте — потоково по дням.
        $orders = [];
        $unreadable = 0;
        foreach ($recordIds as $recordId) {
            foreach ($this->rawStorageFacade->read($recordId, $companyId) as $row) {
                $day = self::day($row['Дата'] ?? null);
                $expense = self::money($row['Расход, ₽'] ?? null);
                if (null === $day || null === $expense) {
                    ++$unreadable;
                    continue;
                }
                $sku = trim((string) ($row['SKU'] ?? ''));
                $orders[(string) ($row['ID заказа'] ?? '').'|'.$sku.'|'.$day] = ['day' => $day, 'sku' => $sku, 'expense' => $expense];
            }
        }

        $byDay = [];
        $skus = [];
        $total = '0';
        $count = 0;
        foreach ($orders as $order) {
            if ($order['day'] < $from->format('Y-m-d') || $order['day'] > $to->format('Y-m-d')) {
                continue;
            }
            $byDay[$order['day']] ??= ['rows' => 0, 'expense' => '0', 'noSku' => 0];
            ++$byDay[$order['day']]['rows'];
            $byDay[$order['day']]['expense'] = bcadd($byDay[$order['day']]['expense'], $order['expense'], 2);
            if ('' === $order['sku']) {
                ++$byDay[$order['day']]['noSku'];
            } else {
                $skus[$order['sku']] = true;
            }
            $total = bcadd($total, $order['expense'], 2);
            ++$count;
        }
        ksort($byDay);

        $rows = [];
        foreach ($byDay as $day => $stat) {
            $rows[] = [$day, $stat['rows'], $stat['expense'], $stat['noSku']];
        }
        $rows[] = ['Итого', $count, $total, array_sum(array_column($byDay, 'noSku'))];
        $io->table(['Дата', 'Строк заказов', 'Расход, ₽', 'Без SKU'], $rows);
        $io->writeln(sprintf('Отчётов прочитано: %d; разных SKU: %d; строк без даты или расхода: %d.', count($recordIds), count($skus), $unreadable));

        return Command::SUCCESS;
    }

    private static function day(mixed $value): ?string
    {
        $value = trim((string) $value);
        foreach (['!d.m.Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $date && $date->format(str_replace('!', '', $format)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @return numeric-string|null
     */
    private static function money(mixed $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($value) ? bcadd($value, '0', 2) : null;
    }
}
