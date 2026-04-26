<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Application\Service\PLSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Backfill / пересчёт регистра ОПиУ (pl_daily_totals + pl_monthly_snapshots).
 *
 * Идемпотентна. Используется для устранения регрессий типа «удвоение storno
 * marketplace_pl» (см. PLRegisterUpdater::aggregateDocuments()) — после
 * фикса алгоритма исторические дни нужно пересчитать.
 */
#[AsCommand(
    name: 'app:finance:recalc-pl-register',
    description: 'Пересчитывает pl_daily_totals и pl_monthly_snapshots за диапазон. Идемпотентна. Поддерживает --dry-run.',
)]
final class RecalcPlRegisterCommand extends Command
{
    private const LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly PLRegisterUpdater $registerUpdater,
        private readonly PLSnapshotBuilder $snapshotBuilder,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        // createFromFormat принимает невалидные даты с rollover (2026-02-30 →
        // 2026-03-02). Round-trip-форматирование ловит это.
        if ($date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'companyId',
                InputArgument::OPTIONAL,
                'UUID компании. Если не задан — итерируется по всем активным компаниям.',
            )
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Дата начала диапазона (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Дата конца диапазона (YYYY-MM-DD)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать diff "до/после" без записи в БД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromArg = $input->getOption('from');
        $toArg = $input->getOption('to');

        if (!is_string($fromArg) || '' === $fromArg || !is_string($toArg) || '' === $toArg) {
            $io->error('Укажите --from и --to в формате YYYY-MM-DD.');

            return Command::FAILURE;
        }

        $from = $this->parseDate($fromArg);
        $to = $this->parseDate($toArg);

        if (null === $from || null === $to) {
            $io->error('Неверный формат дат. Используйте YYYY-MM-DD (без rollover невалидных дат вроде 2026-02-30).');

            return Command::FAILURE;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $companyIdArg = $input->getArgument('companyId');

        try {
            $companies = $this->resolveCompanies(is_string($companyIdArg) && '' !== $companyIdArg ? $companyIdArg : null);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $companies) {
            $io->warning('Не найдено ни одной компании для пересчёта.');

            return Command::SUCCESS;
        }

        $hasFailures = false;

        foreach ($companies as $company) {
            $companyId = (string) $company->getId();
            $lockKey = 'finance_recalc_pl_register_' . $companyId;
            $lock = $this->lockFactory->createLock($lockKey, self::LOCK_TTL_SECONDS);

            if (!$lock->acquire()) {
                $io->warning(sprintf('Компания %s: пересчёт уже идёт, пропускаем.', $companyId));
                continue;
            }

            try {
                $io->section(sprintf('Компания %s (%s)', $companyId, (string) $company->getName()));

                if ($dryRun) {
                    $this->renderDryRun($io, $company, $from, $to);
                    continue;
                }

                $this->registerUpdater->recalcRange($company, $from, $to);
                $this->rebuildMonthlySnapshots($company, $from, $to);

                $io->success(sprintf(
                    'Пересчёт выполнен: %s — %s.',
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                ));
            } catch (\Throwable $e) {
                $hasFailures = true;
                $io->error(sprintf(
                    'Компания %s: ошибка пересчёта — %s',
                    $companyId,
                    $e->getMessage(),
                ));
            } finally {
                $lock->release();
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<Company>
     */
    private function resolveCompanies(?string $companyId): array
    {
        if (null !== $companyId) {
            $company = $this->companyRepository->find($companyId);

            if (!$company instanceof Company) {
                throw new \InvalidArgumentException(sprintf('Компания %s не найдена.', $companyId));
            }

            return [$company];
        }

        $ids = $this->companyRepository->getAllActiveCompanyIds();

        if ([] === $ids) {
            return [];
        }

        return array_values($this->companyRepository->findBy(['id' => $ids]));
    }

    private function rebuildMonthlySnapshots(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->snapshotBuilder->rebuildRange(
            $company,
            $from->format('Y-m'),
            $to->format('Y-m'),
        );
    }

    private function renderDryRun(
        SymfonyStyle $io,
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): void {
        $io->writeln('<info>--dry-run: запускаем реальный пересчёт в транзакции и откатываем её, чтобы посмотреть diff.</info>');

        $before = $this->snapshotTotals($company, $from, $to);

        // Запускаем настоящий пересчёт (через PLRegisterUpdater) внутри
        // DBAL-транзакции и откатываем её — это гарантирует, что diff
        // соответствует реальной логике агрегации, а не её SQL-симуляции.
        $this->connection->beginTransaction();
        try {
            $this->registerUpdater->recalcRange($company, $from, $to);
            $after = $this->snapshotTotals($company, $from, $to);
        } finally {
            $this->connection->rollBack();
            $this->em->clear();
        }

        $rows = [];
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($allKeys);

        foreach ($allKeys as $key) {
            $beforeRow = $before[$key] ?? ['income' => '0.00', 'expense' => '0.00'];
            $afterRow = $after[$key] ?? ['income' => '0.00', 'expense' => '0.00'];

            $deltaIncome = (float) $afterRow['income'] - (float) $beforeRow['income'];
            $deltaExpense = (float) $afterRow['expense'] - (float) $beforeRow['expense'];

            if (0.0 === $deltaIncome && 0.0 === $deltaExpense) {
                continue;
            }

            $rows[] = [
                $key,
                $beforeRow['income'],
                $afterRow['income'],
                number_format($deltaIncome, 2, '.', ''),
                $beforeRow['expense'],
                $afterRow['expense'],
                number_format($deltaExpense, 2, '.', ''),
            ];
        }

        if ([] === $rows) {
            $io->writeln('<comment>Diff пустой — данные уже консистентны.</comment>');

            return;
        }

        $table = new Table($io);
        $table->setHeaders([
            'pl_category_id',
            'income (до)',
            'income (после)',
            'Δ income',
            'expense (до)',
            'expense (после)',
            'Δ expense',
        ]);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * @return array<string, array{income: string, expense: string}>
     */
    private function snapshotTotals(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT pl_category_id::text AS pl_category_id,
       COALESCE(SUM(amount_income), 0)::text AS income,
       COALESCE(SUM(amount_expense), 0)::text AS expense
FROM pl_daily_totals
WHERE company_id = :company AND date BETWEEN :from AND :to
GROUP BY pl_category_id
SQL,
            [
                'company' => (string) $company->getId(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['pl_category_id'] ?? '__null__');
            $result[$key] = [
                'income' => number_format((float) $row['income'], 2, '.', ''),
                'expense' => number_format((float) $row['expense'], 2, '.', ''),
            ];
        }

        return $result;
    }
}
