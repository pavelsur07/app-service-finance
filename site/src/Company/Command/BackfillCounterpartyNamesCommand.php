<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\Application\BackfillCounterpartyNamesAction;
use App\Company\Infrastructure\Query\CounterpartyDuplicateCandidatesQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:counterparty:backfill-names',
    description: 'Recalculates normalized counterparty name fields and reports duplicate candidates.',
)]
final class BackfillCounterpartyNamesCommand extends Command
{
    public function __construct(
        private readonly BackfillCounterpartyNamesAction $backfill,
        private readonly CounterpartyDuplicateCandidatesQuery $duplicates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что изменилось бы, без записи в БД')
            ->addOption('report-company-id', null, InputOption::VALUE_REQUIRED, 'Ограничить ОТЧЁТ одной компанией; сам пересчёт всегда идёт по всем компаниям')
            ->addOption('similarity', null, InputOption::VALUE_REQUIRED, 'Порог похожести названий для отчёта', '0.6');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $companyId = $input->getOption('report-company-id');
        $companyId = is_string($companyId) && '' !== $companyId ? $companyId : null;
        $threshold = (float) $input->getOption('similarity');

        $io->title($dryRun ? 'Backfill названий контрагентов (dry-run)' : 'Backfill названий контрагентов');

        $result = ($this->backfill)($dryRun);

        $io->definitionList(
            ['Обработано' => $result->processed],
            [$dryRun ? 'Изменилось бы' : 'Обновлено' => $result->updated],
            ['Без изменений' => $result->unchanged],
            ['Пропущено (название не нормализуется)' => count($result->skipped)],
        );

        if ([] !== $result->skipped) {
            $io->section('Требуют ручного разбора: название не нормализуется');
            $io->listing($result->skipped);
        }

        $this->reportInvalidInn($io, $companyId);
        $this->reportDuplicates($io, $companyId, $threshold);

        if ($dryRun) {
            $io->note('Dry-run: данные не изменены.');
        }

        return Command::SUCCESS;
    }

    private function reportInvalidInn(SymfonyStyle $io, ?string $companyId): void
    {
        $rows = $this->duplicates->findInvalidInnRows($companyId);
        if ([] === $rows) {
            return;
        }

        $io->section('Некорректный ИНН — правка карточки будет отклонена валидацией');
        $io->table(
            ['company_id', 'id', 'Наименование', 'ИНН'],
            array_map(
                static fn (array $row): array => [$row['company_id'], $row['id'], $row['name'], $row['inn']],
                $rows,
            ),
        );
    }

    private function reportDuplicates(SymfonyStyle $io, ?string $companyId, float $threshold): void
    {
        $innGroups = $this->duplicates->findSameInnGroups($companyId);
        $io->section(sprintf('Кандидаты-дубли по ИНН: %d групп', count($innGroups)));
        if ([] !== $innGroups) {
            $io->table(
                ['company_id', 'ИНН', 'Строк', 'Названия'],
                array_map(
                    static fn (array $row): array => [$row['company_id'], $row['inn'], $row['rows'], $row['names']],
                    $innGroups,
                ),
            );
        }

        $pairs = $this->duplicates->findSimilarNamePairs($threshold, $companyId);
        $io->section(sprintf('Кандидаты-дубли по названию (similarity > %s): %d пар', $threshold, count($pairs)));
        if ([] !== $pairs) {
            $io->table(
                ['company_id', 'ОПФ', 'Похожесть', 'Название A / ИНН', 'Название B / ИНН'],
                array_map(
                    static fn (array $row): array => [
                        $row['company_id'],
                        $row['legal_form_hint'] ?? '—',
                        number_format((float) $row['similarity'], 2),
                        $row['left_name'].' / '.($row['left_inn'] ?? '—'),
                        $row['right_name'].' / '.($row['right_inn'] ?? '—'),
                    ],
                    $pairs,
                ),
            );
        }

        $io->comment('Отчёт ничего не меняет: слияние дублей — отдельная задача.');
    }
}
