<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Service\OzonSearchPromoOrdersReader;
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
    public function __construct(
        private readonly OzonSearchPromoOrdersReader $ordersReader,
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

        $orders = $this->ordersReader->read($companyId, $from, $to);

        $byDay = [];
        $skus = [];
        $total = '0';
        $mismatched = 0;
        foreach ($orders->rows as $order) {
            $byDay[$order->day] ??= ['rows' => 0, 'expense' => '0', 'noSku' => 0];
            ++$byDay[$order->day]['rows'];
            $byDay[$order->day]['expense'] = bcadd($byDay[$order->day]['expense'], $order->expense, 2);
            if ('' === $order->sku) {
                ++$byDay[$order->day]['noSku'];
            } else {
                $skus[$order->sku] = true;
            }
            if ('' !== $order->promotedSku && $order->promotedSku !== $order->sku) {
                ++$mismatched;
            }
            $total = bcadd($total, $order->expense, 2);
        }
        ksort($byDay);

        $rows = [];
        foreach ($byDay as $day => $stat) {
            $rows[] = [$day, $stat['rows'], $stat['expense'], $stat['noSku']];
        }
        $rows[] = ['Итого', count($orders->rows), $total, array_sum(array_column($byDay, 'noSku'))];
        $io->table(['Дата', 'Строк заказов', 'Расход, ₽', 'Без SKU'], $rows);
        $io->writeln(sprintf(
            'Отчётов прочитано: %d; разных SKU: %d; строк без даты или расхода: %d; заказанный SKU ≠ продвигаемому: %d.',
            $orders->reportsRead,
            count($skus),
            $orders->unreadable,
            $mismatched,
        ));

        return Command::SUCCESS;
    }
}
