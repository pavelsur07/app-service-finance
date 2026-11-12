<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceReportClientInterface;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Marketplace\Facade\MarketplaceFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Исследование: все рекламные кампании кабинета Ozon Performance (любых типов) и их расход по
 * загруженному отчёту `expense`. Только чтение: живой GET /api/client/campaign без фильтра типа и
 * сохранённое сырьё; ничего не пишет. Названия кампаний не печатаются.
 *
 * Ingestion грузит кампании только типов SKU и SEARCH_PROMO — команда показывает, каких типов нет.
 */
#[AsCommand(
    name: 'app:ingestion:ozon-performance:campaigns-probe',
    description: 'Read-only: all Ozon Performance campaigns of a company (any type) and their spend from stored expense reports.',
)]
final class OzonPerformanceCampaignsProbeCommand extends Command
{
    private const array LOADED_TYPES = ['SKU', 'SEARCH_PROMO'];
    private const array HIDDEN_FIELDS = ['title', 'name'];
    private const int POLL_ATTEMPTS = 60;
    private const int POLL_SECONDS = 15;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly OzonPerformanceReportClientInterface $client,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Expense period start, YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Expense period end, YYYY-MM-DD.')
            ->addOption('campaign', null, InputOption::VALUE_REQUIRED, 'Campaign id: live objects, products and orders report for the period.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = (string) $input->getOption('company-id');
        $from = null === $input->getOption('from') ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('from'));
        $to = null === $input->getOption('to') ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('to'));
        try {
            Assert::uuid($companyId, 'Invalid --company-id.');
            Assert::true(false !== $from && false !== $to && ((null === $from) === (null === $to)), 'Invalid --from/--to, expected both YYYY-MM-DD.');
            $campaign = $input->getOption('campaign');
            Assert::true(null === $campaign || (null !== $to && 1 === preg_match('/^\d+$/', (string) $campaign)), 'Invalid --campaign, expected digits together with --from/--to.');
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $connections = $this->marketplaceFacade->getActiveOzonPerformanceConnections($companyId);
        if ([] === $connections) {
            $io->warning('No active Ozon Performance connection.');

            return Command::SUCCESS;
        }

        if (null !== $campaign && null !== $from) {
            foreach ($connections as $connection) {
                $this->campaign($io, $companyId, $connection['connectionId'], (string) $campaign, $from, $to);
            }

            return Command::SUCCESS;
        }

        foreach ($connections as $connection) {
            $page = $this->client->listCampaigns($companyId, $connection['connectionId']);
            $rows = [];
            foreach ($page->rows as $campaign) {
                $type = (string) ($campaign['advObjectType'] ?? '');
                $rows[] = [
                    (string) ($campaign['id'] ?? ''),
                    $type,
                    (string) ($campaign['state'] ?? ''),
                    in_array($type, self::LOADED_TYPES, true) ? 'да' : 'НЕТ',
                    self::otherFields($campaign),
                ];
            }
            $io->section(sprintf('Кампании подключения %s: %d', $connection['connectionId'], count($rows)));
            $io->table(['id', 'advObjectType', 'state', 'грузится', 'прочие поля'], $rows);
        }

        if (null !== $from && null !== $to) {
            $this->expense($io, $companyId, $from, $to);
        }

        return Command::SUCCESS;
    }

    private function expense(SymfonyStyle $io, string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $recordIds = $this->rawRecordRepository->findLatestIdsByCompanyAndResource(
            $companyId,
            OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
            'performance-expense-stats:%',
        );

        // (кампания, день) → поля; более поздняя версия окна заменяет раннюю.
        $byCampaignDay = [];
        $keys = [];
        foreach ($recordIds as $recordId) {
            foreach ($this->rawStorageFacade->read($recordId, $companyId) as $row) {
                $keys += array_flip(array_keys($row));
                $campaignId = (string) ($row['id'] ?? $row['campaignId'] ?? $row['campaign_id'] ?? '');
                $day = substr((string) ($row['date'] ?? ''), 0, 10);
                if ('' === $campaignId || $day < $from->format('Y-m-d') || $day > $to->format('Y-m-d')) {
                    continue;
                }
                $byCampaignDay[$campaignId][$day] = $row;
            }
        }

        $rows = [];
        foreach ($byCampaignDay as $campaignId => $days) {
            ksort($days);
            foreach ($days as $day => $row) {
                $amounts = [];
                foreach ($row as $field => $value) {
                    $number = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) (is_scalar($value) ? $value : ''));
                    if (!in_array($field, ['id', 'campaignId', 'campaign_id', 'date'], true) && is_numeric($number)) {
                        $amounts[] = $field.'='.$number;
                    }
                }
                $rows[] = [$campaignId, $day, implode(' ', $amounts)];
            }
        }

        $io->section(sprintf('Расход по отчёту expense %s–%s (отчётов: %d)', $from->format('Y-m-d'), $to->format('Y-m-d'), count($recordIds)));
        $io->writeln('Поля строк: '.implode(', ', array_keys($keys)));
        $io->table(['кампания', 'день', 'числовые поля'], $rows);
    }

    /**
     * Живые read-only запросы по одной кампании: объекты, товары, отчёт по заказам за период.
     * Ошибка одного запроса печатается и не мешает остальным.
     */
    private function campaign(SymfonyStyle $io, string $companyId, string $connectionId, string $campaignId, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $io->section(sprintf('Кампания %s, подключение %s', $campaignId, $connectionId));
        $probes = [
            'objects' => fn (): array => $this->client->fetchCampaignObjects($companyId, $connectionId, $campaignId)->rows,
            'search_promo/v2/products' => fn (): array => $this->client->fetchSearchPromoProducts($companyId, $connectionId, $campaignId, 1)->rows,
            'orders report' => fn (): array => $this->ordersReport($io, $companyId, $connectionId, $campaignId, $from, $to),
        ];
        foreach ($probes as $name => $probe) {
            try {
                $rows = $probe();
            } catch (\Throwable $exception) {
                $io->writeln(sprintf('%s: ОШИБКА %s: %s', $name, $exception::class, mb_substr($exception->getMessage(), 0, 200)));
                continue;
            }
            $keys = [];
            foreach ($rows as $row) {
                $keys += array_flip(array_keys($row));
            }
            $io->writeln(sprintf('%s: строк %d; поля: %s', $name, count($rows), implode(', ', array_keys($keys))));
            if ('orders report' === $name) {
                $this->ordersSummary($io, $rows);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ordersReport(SymfonyStyle $io, string $companyId, string $connectionId, string $campaignId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $uuid = $this->client->generateSearchPromoReport($companyId, $connectionId, 'orders', $from, $to, [$campaignId]);
        // ponytail: синхронный опрос, ~15 мин максимум — разовое исследование, не конвейер.
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; ++$attempt) {
            $link = $this->client->pollReport($companyId, $connectionId, $uuid);
            if (null !== $link) {
                return $this->client->downloadReport($companyId, $connectionId, $uuid, $link)->rows;
            }
            sleep(self::POLL_SECONDS);
        }

        throw new \RuntimeException(sprintf('report %s not ready', $uuid));
    }

    /**
     * Расход по дням, строки без SKU, различные SKU. Колонки — по имени: «Дата», «SKU», «Расход…».
     *
     * @param list<array<string, mixed>> $rows
     */
    private function ordersSummary(SymfonyStyle $io, array $rows): void
    {
        $byDay = [];
        $skus = [];
        $withoutSku = 0;
        foreach ($rows as $row) {
            $expense = '0';
            foreach ($row as $field => $value) {
                if (str_starts_with((string) $field, 'Расход') && is_scalar($value)) {
                    $expense = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $value);
                }
            }
            $day = (string) (is_scalar($row['Дата'] ?? null) ? $row['Дата'] : '');
            $byDay[$day] = bcadd($byDay[$day] ?? '0', is_numeric($expense) ? $expense : '0', 2);
            $sku = trim((string) (is_scalar($row['SKU'] ?? null) ? $row['SKU'] : ''));
            if ('' === $sku) {
                ++$withoutSku;
            } else {
                $skus[$sku] = true;
            }
        }
        ksort($byDay);
        $io->writeln(sprintf('без SKU: %d; различных SKU: %d; всего: %s', $withoutSku, count($skus), array_reduce($byDay, static fn (string $sum, string $v): string => bcadd($sum, $v, 2), '0')));
        $io->table(['день', 'расход'], array_map(null, array_keys($byDay), array_values($byDay)));
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private static function otherFields(array $campaign): string
    {
        $parts = [];
        foreach ($campaign as $field => $value) {
            if (in_array($field, ['id', 'advObjectType', 'state', ...self::HIDDEN_FIELDS], true)) {
                continue;
            }
            $parts[] = is_scalar($value) || null === $value
                ? $field.'='.var_export($value, true)
                : $field.'[]';
        }

        return implode(' ', $parts);
    }
}
