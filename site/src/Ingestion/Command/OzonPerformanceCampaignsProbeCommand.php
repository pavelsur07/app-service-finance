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
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Expense period end, YYYY-MM-DD.');
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
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $connections = $this->marketplaceFacade->getActiveOzonPerformanceConnections($companyId);
        if ([] === $connections) {
            $io->warning('No active Ozon Performance connection.');

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
