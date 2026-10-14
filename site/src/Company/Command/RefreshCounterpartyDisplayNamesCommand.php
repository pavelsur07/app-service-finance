<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\Application\RefreshCounterpartyDisplayNamesAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:counterparty:refresh-display-names',
    description: 'Recalculates counterparty display names by the current rules (raw names untouched).',
)]
final class RefreshCounterpartyDisplayNamesCommand extends Command
{
    public function __construct(private readonly RefreshCounterpartyDisplayNamesAction $refresh)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только отчёт «было → станет», без записи в БД')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Сколько примеров показать в каждом разделе отчёта (только с --dry-run)', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $samples = filter_var($input->getOption('samples'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false === $samples) {
            $io->error('--samples: целое число от 0.');

            return Command::INVALID;
        }

        $io->title($dryRun ? 'Отображаемые названия контрагентов (dry-run)' : 'Отображаемые названия контрагентов');

        // Примеры (сырые названия, ФИО ИП) — только в dry-run для отчёта: плановый прогон пишет
        // stdout в docker logs планировщика, там персональным данным не место.
        $result = ($this->refresh)($dryRun, $dryRun ? $samples : 0);

        $io->definitionList(
            ['Обработано' => $result->processed],
            [$dryRun ? 'Изменилось бы' : 'Изменено' => $result->changed],
            ['  из них стало нераспознанным' => $result->becameUnrecognised],
            ['Без изменений' => $result->unchanged],
            ['Не распознано (показывается сырое название)' => $result->unrecognised],
            ['Пропущено: изменены во время прогона' => $result->skippedConcurrent],
        );

        if ([] !== $result->changedSamples) {
            $io->section(sprintf('Было → станет (первые %d)', \count($result->changedSamples)));
            $io->table(
                ['company_id', 'id', 'Сырое название', 'Отображаемое'],
                array_map(static fn (array $row): array => [$row['companyId'], $row['id'], $row['raw'], $row['after']], $result->changedSamples),
            );
        }

        if ([] !== $result->unrecognisedSamples) {
            $io->section(sprintf('Не распознано (первые %d)', \count($result->unrecognisedSamples)));
            $io->table(
                ['company_id', 'id', 'Сырое название'],
                array_map(static fn (array $row): array => [$row['companyId'], $row['id'], $row['raw']], $result->unrecognisedSamples),
            );
        }

        if ($dryRun) {
            $io->note('Dry-run: данные не изменены.');
        }

        return Command::SUCCESS;
    }
}
