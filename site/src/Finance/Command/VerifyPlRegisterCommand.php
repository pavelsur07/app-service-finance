<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Company\Entity\Company;
use App\Finance\Application\DTO\PLRegisterRow;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Infrastructure\Query\PLRegisterCompanyRangesQuery;
use App\Finance\Repository\PLDailyTotalRepository;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Сверка регистра ОПиУ с документами без записи: окно компании пересчитывается в памяти тем же
 * кодом, что и `PLRegisterUpdater::recalcRange()`, и сравнивается с `pl_daily_totals` по ключу
 * (день, статья, проект, ЦФО). Обход — по компании и календарному месяцу; месяц читается одним
 * снимком БД (REPEATABLE READ, откат после чтения), после месяца EntityManager очищается.
 *
 * Строка регистра без статьи, которой нет в документах, — след удаления статьи
 * (`DeletePLCategoryAction` переносит её суммы в «без статьи»): печатается отдельно и сверку не
 * валит. Ненулевой exit code и один агрегированный `error` (GlitchTip) — только при недостающих,
 * лишних или расходящихся строках со статьёй.
 */
#[AsCommand(
    name: 'app:finance:verify-pl-register',
    description: 'Сверяет регистр ОПиУ (pl_daily_totals) с документами без изменения данных',
)]
final class VerifyPlRegisterCommand extends Command
{
    private const MAX_SAMPLE_DAYS = 5;

    public function __construct(
        private readonly PLRegisterUpdater $registerUpdater,
        private readonly PLDailyTotalRepository $dailyTotals,
        private readonly PLRegisterCompanyRangesQuery $companyRanges,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании; без опции — все компании')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Первый день окна, Y-m-d')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Последний день окна, Y-m-d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company');
        if (null !== $companyId && (!\is_string($companyId) || !Uuid::isValid($companyId))) {
            $io->error('--company должен быть UUID.');

            return Command::INVALID;
        }

        $from = $this->parseDay($input->getOption('from'));
        $to = $this->parseDay($input->getOption('to'));
        if (false === $from || false === $to) {
            $io->error('--from и --to задаются в формате Y-m-d.');

            return Command::INVALID;
        }

        if (null !== $from && null !== $to && $from > $to) {
            $io->error('--from позже --to: окно пустое, сверять нечего.');

            return Command::INVALID;
        }

        $tableRows = [];
        $totals = ['companies' => 0, 'expected' => 0, 'actual' => 0, 'missing' => 0, 'extra' => 0, 'mismatch' => 0, 'uncategorized' => 0];
        $diffSum = '0.00';
        $uncategorizedSum = '0.00';

        foreach (($this->companyRanges)($companyId) as $range) {
            $windowFrom = null !== $from && $from > $range->from ? $from : $range->from;
            $windowTo = null !== $to && $to < $range->to ? $to : $range->to;
            if ($windowFrom > $windowTo) {
                continue;
            }

            ++$totals['companies'];
            $company = ['missing' => 0, 'extra' => 0, 'mismatch' => 0, 'uncategorized' => 0, 'diff' => '0.00', 'days' => []];

            foreach ($this->monthWindows($windowFrom, $windowTo) as [$monthFrom, $monthTo]) {
                [$expected, $actual] = $this->readMonth($range->companyId, $monthFrom, $monthTo);

                $totals['expected'] += \count($expected);
                $totals['actual'] += \count($actual);

                foreach ($expected as $key => $row) {
                    $stored = $actual[$key] ?? null;
                    if (null === $stored) {
                        ++$company['missing'];
                        $company['diff'] = bcadd($company['diff'], $this->rowWeight($row), 2);
                        $company['days'][$row->date] = true;

                        continue;
                    }

                    $delta = bcadd(
                        $this->abs(bcsub($row->income, $stored->income, 2)),
                        $this->abs(bcsub($row->expense, $stored->expense, 2)),
                        2,
                    );
                    if (0 !== bccomp($delta, '0', 2)) {
                        ++$company['mismatch'];
                        $company['diff'] = bcadd($company['diff'], $delta, 2);
                        $company['days'][$row->date] = true;
                    }
                }

                foreach ($actual as $key => $row) {
                    if (isset($expected[$key])) {
                        continue;
                    }

                    if (null === $row->categoryId) {
                        ++$company['uncategorized'];
                        $uncategorizedSum = bcadd($uncategorizedSum, $this->rowWeight($row), 2);

                        continue;
                    }

                    ++$company['extra'];
                    $company['diff'] = bcadd($company['diff'], $this->rowWeight($row), 2);
                    $company['days'][$row->date] = true;
                }
            }

            foreach (['missing', 'extra', 'mismatch', 'uncategorized'] as $kind) {
                $totals[$kind] += $company[$kind];
            }
            $diffSum = bcadd($diffSum, $company['diff'], 2);

            if ($company['missing'] + $company['extra'] + $company['mismatch'] + $company['uncategorized'] > 0) {
                $days = array_keys($company['days']);
                sort($days);
                $tableRows[] = [
                    $range->companyId,
                    $company['missing'],
                    $company['extra'],
                    $company['mismatch'],
                    $company['diff'],
                    $company['uncategorized'],
                    implode(', ', \array_slice($days, 0, self::MAX_SAMPLE_DAYS)).(\count($days) > self::MAX_SAMPLE_DAYS ? sprintf(' … (%d дн.)', \count($days)) : ''),
                ];
            }
        }

        if ([] !== $tableRows) {
            $io->table(['Компания', 'Нет в регистре', 'Лишние', 'Расходятся', 'Σ|разница|', 'Без статьи', 'Дни'], $tableRows);
        }

        $io->definitionList(
            ['Компаний' => $totals['companies']],
            ['Строк ожидается' => $totals['expected']],
            ['Строк в регистре' => $totals['actual']],
            ['Нет в регистре' => $totals['missing']],
            ['Лишние (со статьёй)' => $totals['extra']],
            ['Расходятся суммы' => $totals['mismatch']],
            ['Σ|разница|' => $diffSum],
            ['Без статьи только в регистре' => sprintf('%d (Σ %s)', $totals['uncategorized'], $uncategorizedSum)],
        );

        if ($totals['missing'] + $totals['extra'] + $totals['mismatch'] > 0) {
            // Один агрегированный event на запуск со стабильным текстом; починка —
            // app:finance:recalc-pl-register по компании и окну.
            $this->logger->error('PL register differs from documents', [
                'companies_checked' => $totals['companies'],
                'missing' => $totals['missing'],
                'extra' => $totals['extra'],
                'mismatch' => $totals['mismatch'],
                'diff_sum' => $diffSum,
                'companies' => array_column(array_filter($tableRows, static fn (array $row): bool => $row[1] + $row[2] + $row[3] > 0), 0),
            ]);
            $io->error('Регистр ОПиУ расходится с документами.');

            return Command::FAILURE;
        }

        $io->success('Регистр ОПиУ совпадает с документами.');

        return Command::SUCCESS;
    }

    /**
     * Документы и строки регистра месяца — из одного снимка БД (REPEATABLE READ): иначе документ
     * с регистром, закоммиченные между двумя чтениями, дали бы ложное расхождение. Транзакция
     * только читает и откатывается; прежний уровень изоляции соединения возвращается.
     *
     * @return array{0: array<string, PLRegisterRow>, 1: array<string, PLRegisterRow>}
     */
    private function readMonth(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->em->getConnection();
        $previousIsolation = $connection->getTransactionIsolation();

        $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        try {
            $connection->beginTransaction();
            try {
                $expected = $this->index($this->registerUpdater->calculateRows(
                    $this->em->getReference(Company::class, $companyId)
                        ?? throw new \LogicException(sprintf('Компания %s не найдена.', $companyId)),
                    $from,
                    $to,
                ));
                $actual = $this->index($this->dailyTotals->findRowsForRange($companyId, $from, $to));

                return [$expected, $actual];
            } finally {
                $connection->rollBack();
                $this->em->clear();
            }
        } finally {
            $connection->setTransactionIsolation($previousIsolation);
        }
    }

    private function parseDay(mixed $value): \DateTimeImmutable|false|null
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            return false;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $day && $day->format('Y-m-d') === $value ? $day : false;
    }

    /**
     * @return iterable<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private function monthWindows(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $cursor = $from->setTime(0, 0);
        $last = $to->setTime(0, 0);

        while ($cursor <= $last) {
            $monthEnd = $cursor->modify('last day of this month');
            yield [$cursor, $monthEnd < $last ? $monthEnd : $last];
            $cursor = $monthEnd->modify('+1 day');
        }
    }

    /**
     * @param list<PLRegisterRow> $rows
     *
     * @return array<string, PLRegisterRow>
     */
    private function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->key()] = $row;
        }

        return $indexed;
    }

    /**
     * @return numeric-string
     */
    private function rowWeight(PLRegisterRow $row): string
    {
        return bcadd($this->abs($row->income), $this->abs($row->expense), 2);
    }

    /**
     * @return numeric-string
     */
    private function abs(string $value): string
    {
        return -1 === bccomp($value, '0', 2) ? bcmul($value, '-1', 2) : bcadd($value, '0', 2);
    }
}
