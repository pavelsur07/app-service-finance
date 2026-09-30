<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Command;

use App\Marketplace\Wildberries\Infrastructure\Query\WbUnrecognizedCostsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт нераспознанных затрат WB: в окне последних N дней у активных seller-
 * подключений не должно быть операций, которые не поддерживает ни один
 * калькулятор затрат.
 *
 * Охват проверки равен охвату починки. Окно по умолчанию — 14 дней, как
 * `--refresh-days-back` ночного orchestrate: документы старше окна гейт не
 * краснят, их правит только ручной пересчёт `app:marketplace:reprocess`.
 * Компания без активного подключения не проверяется.
 *
 * Читает готовые счётчики документов (`unprocessed_cost_types`), а не raw_data.
 * Документ, у которого шаг `costs` не отработал, счётчик не заполнял, поэтому
 * такие документы выводятся отдельной строкой `costs not processed` — это
 * предупреждение, а не ошибка: чинит его пересчёт, а не калькулятор.
 *
 * Под состояние «красный» есть операция: новый тип операции чинится
 * калькулятором и пересчётом по компании и периоду — после этого счётчик
 * документа обнуляется, и гейт зеленеет сам.
 */
#[AsCommand(
    name: 'app:marketplace:wb-costs:unrecognized-check',
    description: 'Read-only gate: у активных WB-подключений нет нераспознанных операций затрат за последние N дней',
)]
final class WbUnrecognizedCostsCheckCommand extends Command
{
    private const int DEFAULT_DAYS_BACK = 14;
    private const int MAX_DAYS_BACK = 90;
    private const int MAX_LOGGED_OPERATIONS = 20;

    public function __construct(
        private readonly WbUnrecognizedCostsQuery $query,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days-back',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Окно проверки в днях (1..%d)', self::MAX_DAYS_BACK),
            (string) self::DEFAULT_DAYS_BACK,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysBackOption = (string) $input->getOption('days-back');
        if (1 !== preg_match('/^[1-9][0-9]{0,2}$/', $daysBackOption) || (int) $daysBackOption > self::MAX_DAYS_BACK) {
            $output->writeln(sprintf('--days-back должен быть целым числом от 1 до %d', self::MAX_DAYS_BACK));

            return self::INVALID;
        }
        $daysBack = (int) $daysBackOption;

        $output->writeln('start');

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $fromDay = $today->modify(sprintf('-%d days', $daysBack));

        $output->writeln(sprintf('window: %s - %s', $fromDay->format('Y-m-d'), $today->format('Y-m-d')));

        $unrecognized = $this->query->findUnrecognized($fromDay);
        $coverage = $this->query->coverage($fromDay);

        $totalRows = 0;
        $rowsByOperation = [];
        $companies = [];

        foreach ($unrecognized as $row) {
            $totalRows += $row['rows_count'];
            $rowsByOperation[$row['operation']] = ($rowsByOperation[$row['operation']] ?? 0) + $row['rows_count'];
            $companies[$row['company_id']] = true;

            $output->writeln(sprintf(
                'UNRECOGNIZED company %s: «%s» — %d строк в %d документах (%s - %s)',
                $row['company_id'],
                $row['operation'],
                $row['rows_count'],
                $row['documents'],
                $row['first_day'],
                $row['last_day'],
            ));
        }

        $output->writeln(sprintf('checked documents count: %d', $coverage['documents']));
        $output->writeln(sprintf('checked companies count: %d', $coverage['companies']));
        $output->writeln(sprintf('costs not processed count: %d', $coverage['costs_not_processed']));
        $output->writeln(sprintf('unrecognized rows count: %d', $totalRows));
        $output->writeln(sprintf('unrecognized operations count: %d', count($rowsByOperation)));
        $output->writeln('finish');

        if ($coverage['costs_not_processed'] > 0) {
            $this->logger->warning('WB documents in the window have no processed costs step; unrecognized counters are not filled for them.', [
                'days_back' => $daysBack,
                'costs_not_processed' => $coverage['costs_not_processed'],
                'checked_documents' => $coverage['documents'],
            ]);
        }

        if ([] === $unrecognized) {
            return self::SUCCESS;
        }

        arsort($rowsByOperation);

        // Один агрегированный error со счётчиком, а не запись на компанию или
        // документ: новая операция WB — инцидент, который сам не рассосётся, и
        // человека будить нужно один раз. Названия операций — данные WB, не
        // персональные; тела ответов и реквизиты в лог не попадают.
        $this->logger->error('WB cost operations are not recognized by any calculator.', [
            'days_back' => $daysBack,
            'checked_documents' => $coverage['documents'],
            'affected_companies' => count($companies),
            'unrecognized_rows' => $totalRows,
            'operations' => array_slice($rowsByOperation, 0, self::MAX_LOGGED_OPERATIONS, true),
        ]);

        return self::FAILURE;
    }
}
