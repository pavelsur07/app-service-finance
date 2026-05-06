<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\OzonRawDuplicateAuditQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:marketplace:ozon-raw-duplicates-audit',
    description: 'Read-only диагностика дублей Ozon raw-документов и обработанных данных',
)]
final class OzonRawDuplicatesAuditCommand extends Command
{
    public function __construct(
        private readonly OzonRawDuplicateAuditQuery $auditQuery,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании (optional)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Начало периода (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Конец периода (YYYY-MM-DD)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Формат вывода: table|json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromOption = $input->getOption('from');
        $toOption = $input->getOption('to');
        $companyIdOption = $input->getOption('company-id');
        $format = (string) $input->getOption('format');

        if (!is_string($fromOption) || $fromOption === '' || !is_string($toOption) || $toOption === '') {
            $io->error('Опции --from и --to обязательны. Используйте формат YYYY-MM-DD.');

            return Command::FAILURE;
        }

        $from = $this->parseStrictDate($fromOption);
        $to = $this->parseStrictDate($toOption);

        if ($from === null || $to === null) {
            $io->error('Неверный формат дат. Используйте реальные даты в формате YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($from > $to) {
            $io->error('Опция --from должна быть меньше или равна --to.');

            return Command::FAILURE;
        }

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Опция --format должна быть table или json.');

            return Command::FAILURE;
        }

        $companyId = null;
        if (is_string($companyIdOption) && $companyIdOption !== '') {
            try {
                Assert::uuid($companyIdOption);
            } catch (\InvalidArgumentException) {
                $io->error('Некорректный формат --company-id, ожидается UUID.');

                return Command::FAILURE;
            }
            $companyId = $companyIdOption;
        }

        $exactDuplicates = $this->auditQuery->findExactRawDocumentDuplicates($companyId, $from, $to);
        $overlapping = $this->auditQuery->findOverlappingRawDocuments($companyId, $from, $to);
        $salesDuplicates = $this->auditQuery->findProcessedSalesWithMultipleRawDocuments($companyId, $from, $to);
        $returnsDuplicates = $this->auditQuery->findProcessedReturnsWithMultipleRawDocuments($companyId, $from, $to);
        $costsDuplicates = $this->auditQuery->findProcessedCostsWithMultipleRawDocuments($companyId, $from, $to);

        $payload = [
            'filters' => [
                'company_id' => $companyId,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'exact_raw_duplicates' => $exactDuplicates,
            'overlapping_raw_documents' => $overlapping,
            'processed_sales_duplicates' => $salesDuplicates,
            'processed_returns_duplicates' => $returnsDuplicates,
            'processed_costs_duplicates' => $costsDuplicates,
        ];

        if ($format === 'json') {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('Ozon raw duplicates audit (read-only)');
        $io->definitionList(
            ['Company ID' => $companyId ?? 'ALL'],
            ['From' => $from->format('Y-m-d')],
            ['To' => $to->format('Y-m-d')],
        );

        $this->renderSectionTable($io, '1. Exact raw document duplicates', $exactDuplicates);
        $this->renderSectionTable($io, '2. Overlapping raw documents', $overlapping);
        $this->renderSectionTable($io, '3. Processed sales duplicates', $salesDuplicates);
        $this->renderSectionTable($io, '4. Processed returns duplicates', $returnsDuplicates);
        $this->renderSectionTable($io, '5. Processed costs duplicates', $costsDuplicates);

        $io->success('Диагностика завершена. Команда выполняет только SELECT-запросы и не изменяет данные.');

        return Command::SUCCESS;
    }

    private function renderSectionTable(SymfonyStyle $io, string $title, array $rows): void
    {
        $io->section($title);

        if ($rows === []) {
            $io->text('No rows found.');

            return;
        }

        $headers = array_keys($rows[0]);
        $tableRows = array_map(
            static function (array $row): array {
                return array_map(static fn (mixed $value): string => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value, $row);
            },
            $rows,
        );

        $io->table($headers, $tableRows);
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
