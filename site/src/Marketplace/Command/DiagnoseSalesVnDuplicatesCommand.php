<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:marketplace:diagnose-sales-vn-duplicates',
    description: 'Диагностика дублей marketplace_sales с external_order_id_vN',
)]
final class DiagnoseSalesVnDuplicatesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $marketplaces = implode(', ', array_map(static fn (MarketplaceType $m): string => $m->value, MarketplaceType::cases()));

        $this
            ->addArgument('marketplace', InputArgument::REQUIRED, "Маркетплейс: {$marketplaces}")
            ->addArgument('from', InputArgument::REQUIRED, 'Начало периода (Y-m-d)')
            ->addArgument('to', InputArgument::REQUIRED, 'Конец периода (Y-m-d)')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании (опционально)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Лимит топа дублей', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $marketplace = (string) $input->getArgument('marketplace');
        $fromStr = (string) $input->getArgument('from');
        $toStr = (string) $input->getArgument('to');
        $companyId = $input->getOption('company-id');
        $limit = (int) $input->getOption('limit');

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $io->error(sprintf('Неизвестный marketplace: %s', $marketplace));

            return Command::FAILURE;
        }

        $from = $this->parseStrictDate($fromStr);
        $to = $this->parseStrictDate($toStr);
        if ($from === null || $to === null) {
            $io->error('Неверный формат даты. Используйте реальную дату в формате Y-m-d.');

            return Command::FAILURE;
        }

        if ($from > $to) {
            $io->error('Параметр from должен быть <= to.');

            return Command::FAILURE;
        }

        if ($limit <= 0) {
            $io->error('Параметр --limit должен быть > 0.');

            return Command::FAILURE;
        }

        if (is_string($companyId) && $companyId !== '') {
            try {
                Assert::uuid($companyId);
            } catch (\InvalidArgumentException) {
                $io->error('Некорректный формат --company-id, ожидается UUID.');

                return Command::FAILURE;
            }
        }

        $io->title('Диагностика _vN дублей marketplace_sales');
        $io->definitionList(
            ['Marketplace' => $marketplace],
            ['Period from' => $from->format('Y-m-d')],
            ['Period to' => $to->format('Y-m-d')],
            ['Company ID' => is_string($companyId) && $companyId !== '' ? $companyId : 'ALL'],
        );

        $params = [
            'marketplace' => $marketplace,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];
        $companyFilter = '';
        if (is_string($companyId) && $companyId !== '') {
            $params['companyId'] = $companyId;
            $companyFilter = ' AND s.company_id = :companyId';
        }

        $summary = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*) FILTER (WHERE s.external_order_id !~ '_v[0-9]+$') AS base_count,
                COUNT(*) FILTER (WHERE s.external_order_id ~ '_v[0-9]+$') AS versioned_count,
                COALESCE(SUM(s.total_revenue) FILTER (WHERE s.external_order_id !~ '_v[0-9]+$'), 0) AS base_revenue,
                COALESCE(SUM(s.total_revenue) FILTER (WHERE s.external_order_id ~ '_v[0-9]+$'), 0) AS versioned_revenue
            FROM marketplace_sales s
            WHERE s.marketplace = :marketplace
              AND s.sale_date BETWEEN :periodFrom AND :periodTo
              {$companyFilter}
            SQL,
            $params,
        );

        $topRows = $this->connection->fetchAllAssociative(
            <<<SQL
            WITH normalized AS (
                SELECT
                    s.company_id,
                    s.external_order_id,
                    regexp_replace(s.external_order_id, '_v[0-9]+$', '') AS base_external_order_id,
                    s.total_revenue
                FROM marketplace_sales s
                WHERE s.marketplace = :marketplace
                  AND s.sale_date BETWEEN :periodFrom AND :periodTo
                  {$companyFilter}
            )
            SELECT
                n.company_id,
                n.base_external_order_id,
                COUNT(*) FILTER (WHERE n.external_order_id !~ '_v[0-9]+$') AS base_rows,
                COUNT(*) FILTER (WHERE n.external_order_id ~ '_v[0-9]+$') AS versioned_rows,
                COALESCE(SUM(n.total_revenue) FILTER (WHERE n.external_order_id !~ '_v[0-9]+$'), 0) AS base_revenue,
                COALESCE(SUM(n.total_revenue) FILTER (WHERE n.external_order_id ~ '_v[0-9]+$'), 0) AS versioned_revenue
            FROM normalized n
            GROUP BY n.company_id, n.base_external_order_id
            HAVING COUNT(*) FILTER (WHERE n.external_order_id ~ '_v[0-9]+$') > 0
            ORDER BY versioned_rows DESC, base_rows DESC, n.base_external_order_id ASC
            LIMIT {$limit}
            SQL,
            $params,
        );

        $io->section('Итоги по периоду');
        $io->table(
            ['base_rows', 'versioned_vN_rows', 'base_revenue', 'versioned_vN_revenue'],
            [[
                (string) ((int) ($summary['base_count'] ?? 0)),
                (string) ((int) ($summary['versioned_count'] ?? 0)),
                (string) ($summary['base_revenue'] ?? '0'),
                (string) ($summary['versioned_revenue'] ?? '0'),
            ]],
        );

        $io->section(sprintf('Топ дублей (_vN), limit=%d', $limit));
        if ($topRows === []) {
            $io->success('Дубли с суффиксом _vN не найдены.');

            return Command::SUCCESS;
        }

        $io->table(
            ['company_id', 'base_external_order_id', 'base_rows', 'versioned_rows', 'base_revenue', 'versioned_revenue'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['company_id'],
                    (string) $row['base_external_order_id'],
                    (string) ((int) $row['base_rows']),
                    (string) ((int) $row['versioned_rows']),
                    (string) ($row['base_revenue'] ?? '0'),
                    (string) ($row['versioned_revenue'] ?? '0'),
                ],
                $topRows,
            ),
        );

        $io->warning('Команда только читает данные (SELECT) и не изменяет БД.');

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
