<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\ImportOzonAccrualDetailsAction;
use App\Ingestion\Application\DTO\OzonAccrualDetailsTypeResult;
use App\Ingestion\Exception\OzonAccrualDetailsFileException;
use App\Ingestion\Infrastructure\Xlsx\OzonAccrualDetailsXlsxReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Импорт отчёта кабинета Ozon «Детализация начислений»: группы Ozon по типам
 * начислений. Строки отчёта связываются с by-day указанной компании, поэтому
 * отчёт должен быть выгружен из её кабинета. Файл не сохраняется.
 */
#[AsCommand(
    name: 'app:ingestion:ozon-accrual:import-details-xlsx',
    description: 'Derives Ozon accrual type groups from the cabinet "accrual details" xlsx report.',
)]
final class OzonAccrualImportDetailsXlsxCommand extends Command
{
    public function __construct(
        private readonly OzonAccrualDetailsXlsxReader $reader,
        private readonly ImportOzonAccrualDetailsAction $importAction,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID whose Ozon cabinet produced the report.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the xlsx report.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show derived groups without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = trim((string) $input->getOption('company-id'));
        $file = trim((string) $input->getOption('file'));
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            Assert::uuid($companyId, 'Invalid --company-id UUID.');
            Assert::notEmpty($file, 'The --file option is required.');
            Assert::readable($file, 'The report file is not readable.');
            $report = $this->reader->read($file);
        } catch (\InvalidArgumentException|OzonAccrualDetailsFileException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $result = ($this->importAction)($companyId, $report->rows, $dryRun);

        $io->title('Ozon accrual details import');
        $io->text(sprintf('Rows: %d (skipped incomplete: %d), period: %s — %s', $result->rows, $report->skippedRows, $result->periodFrom ?? '-', $result->periodTo ?? '-'));
        if ([] !== $result->types && \count($result->withOutcome(OzonAccrualDetailsTypeResult::UNMATCHED)) === \count($result->types)) {
            $io->warning('No report row matched Ozon by-day data of this company: check that the report comes from its cabinet and the period is loaded.');
        }
        $io->table(
            ['outcome', 'typeId', 'group', 'votes/rows', 'report group', 'report type'],
            array_map(static fn (OzonAccrualDetailsTypeResult $type): array => [
                $type->outcome.($type->groupChanged ? ' (changed)' : ''),
                $type->typeId ?? '-',
                $type->groupCode ?? '-',
                sprintf('%d/%d', $type->votes, $type->rowsTotal),
                $type->xlsxGroupLabel,
                $type->xlsxTypeName,
            ], $result->types),
        );

        if ($dryRun) {
            $io->note('Dry-run only. ingest_ozon_accrual_type_groups was not changed.');
        } else {
            $io->success(sprintf('Confirmed groups: %d.', \count($result->withOutcome(OzonAccrualDetailsTypeResult::CONFIRMED))));
        }

        return Command::SUCCESS;
    }
}
