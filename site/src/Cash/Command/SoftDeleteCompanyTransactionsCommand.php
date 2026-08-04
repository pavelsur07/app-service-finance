<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Массовый soft delete (и откат) всех транзакций ДДС компании.
 *
 * Почему один DBAL-UPDATE, а не CashTransactionService::delete() по каждой строке:
 * сервис на каждую транзакцию пересчитывает диапазон дневных остатков, то есть
 * массовое удаление стоило бы O(N) полных recalc. Здесь мутация идёт одним запросом
 * с теми же полями, что ставит markDeleted() (deleted_at/deleted_by/delete_reason),
 * а пересчёт выполняется один раз на весь затронутый диапазон после фиксации.
 *
 * Замок периода обрабатывается жёстче, чем в UI: если хотя бы одна транзакция
 * компании попадает в закрытый период, операция отклоняется целиком до любых
 * изменений — частичный массовый delete оставил бы регистры в состоянии, которое
 * владелец замка не санкционировал.
 *
 * Идемпотентна: предикаты delete/restore взаимно симметричны, повторный запуск —
 * no-op, обрыв после UPDATE лечится повторным запуском (пересчёт остатков
 * идемпотентен). Финальный вердикт строится по пересчёту строк из БД, а не по
 * счётчику executeStatement, чтобы прерванный прогон не отчитался успехом.
 *
 * updated_at намеренно не трогаем: CashTransactionService::delete() его тоже не
 * обновляет (Timestampable-листенеров в проекте нет), массовая операция не должна
 * отличаться от одиночной.
 *
 * Запускать в тихом окне с остановленными воркерами: пречек замка и UPDATE не
 * в одной транзакции, и импорт, попавший между ними, будет подметён массовым
 * UPDATE без замка. В одном экземпляре — параллельные прогоны бессмысленны.
 */
#[AsCommand(
    name: 'app:cash:soft-delete-company-transactions',
    description: 'Soft delete всех транзакций ДДС компании; --restore откатывает. Без --execute только считает.',
)]
final class SoftDeleteCompanyTransactionsCommand extends Command
{
    /**
     * Маркер в deleted_by вместо user id: у CLI нет пользователя, а значение должно
     * отличать массовую операцию от одиночного удаления через UI.
     */
    private const ACTOR = 'cli:app:cash:soft-delete-company-transactions';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companyRepository,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить изменения; без флага команда только считает')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Откат: снять soft delete со всех транзакций компании')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Причина удаления (пишется в delete_reason)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = true === $input->getOption('execute');
        $restore = true === $input->getOption('restore');
        /** @var string|null $reason */
        $reason = $input->getOption('reason');
        if ($restore && null !== $reason) {
            $io->warning('Опция --reason игнорируется при --restore: аудит-поля очищаются.');
        }

        $companyId = (string) $input->getArgument('companyId');
        $company = $this->companyRepository->find($companyId);
        if (!$company instanceof Company) {
            $io->error(sprintf('Компания %s не найдена.', $companyId));

            return Command::FAILURE;
        }

        $connection = $this->entityManager->getConnection();
        $active = $this->countByDeletedState($companyId, false);
        $deleted = $this->countByDeletedState($companyId, true);

        $io->writeln(sprintf('Компания: %s', $company->getName() ?? $companyId));
        $io->writeln(sprintf('Активных транзакций: %d, soft-deleted: %d', $active, $deleted));

        $target = $restore ? $deleted : $active;
        if (0 === $target) {
            $io->success($restore ? 'Восстанавливать нечего.' : 'Удалять нечего.');

            return Command::SUCCESS;
        }

        // Замок периода: любая транзакция целевого набора раньше financeLockBefore
        // отклоняет операцию целиком. Проверка до ветки dry-run, чтобы оценка
        // не обещала применение там, где --execute будет отклонён.
        $lock = $company->getFinanceLockBefore();
        $locked = null !== $lock && $this->hasLockedRows($companyId, $restore, $lock);

        if (!$execute) {
            $io->warning(sprintf(
                'Read-only режим. Будет %s: %d транзакций. Повторите с --execute, чтобы применить.',
                $restore ? 'восстановлено' : 'помечено удалёнными',
                $target,
            ));
            if ($locked && null !== $lock) {
                $io->warning(sprintf(
                    'Внимание: --execute будет отклонён — у компании есть транзакции в закрытом периоде (раньше %s).',
                    $lock->format('Y-m-d'),
                ));
            }

            return Command::SUCCESS;
        }

        if ($locked && null !== $lock) {
            $io->error(sprintf(
                'Операция отклонена: у компании есть транзакции в закрытом периоде (раньше %s).',
                $lock->format('Y-m-d'),
            ));

            return Command::FAILURE;
        }

        // Диапазон пересчёта фиксируем до мутации: после UPDATE целевой набор
        // перестанет отличаться предикатом.
        $minOccurredAt = $this->minOccurredAt($companyId, $restore);

        if ($restore) {
            $connection->executeStatement(
                'UPDATE cash_transaction SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL
                 WHERE company_id = :companyId AND deleted_at IS NOT NULL',
                ['companyId' => $companyId],
            );
        } else {
            $connection->executeStatement(
                'UPDATE cash_transaction SET deleted_at = NOW(), deleted_by = :actor, delete_reason = :reason
                 WHERE company_id = :companyId AND deleted_at IS NULL',
                ['companyId' => $companyId, 'actor' => self::ACTOR, 'reason' => $reason],
            );
        }

        // UPDATE прошёл мимо UnitOfWork: сбрасываем, чтобы пересчёт и верификация
        // читали БД, а не закэшированные сущности.
        $this->entityManager->clear();
        $company = $this->companyRepository->find($companyId);
        \assert($company instanceof Company);

        if (null !== $minOccurredAt) {
            // Порядок как в CashTransactionService::delete(): сначала фиксация,
            // потом пересчёт фактов, и только после этого новая версия snapshot cache.
            $this->recalculator->recalcRange(
                $company,
                $minOccurredAt->setTime(0, 0),
                (new \DateTimeImmutable('today'))->setTime(0, 0),
                null,
            );
        }
        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        // Верификация по пересчёту из БД: после delete в исходном состоянии (активные)
        // не должно остаться строк, после restore — в исходном состоянии (deleted).
        $remaining = $this->countByDeletedState($companyId, $restore);
        if ($remaining > 0) {
            $io->error(sprintf(
                'Операция не завершена: %d транзакций остались в исходном состоянии. Повторите команду.',
                $remaining,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s %d транзакций.',
            $restore ? 'Восстановлено' : 'Помечено удалёнными',
            $target,
        ));

        return Command::SUCCESS;
    }

    private function countByDeletedState(string $companyId, bool $deleted): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM cash_transaction
             WHERE company_id = :companyId AND deleted_at IS '.($deleted ? 'NOT NULL' : 'NULL'),
            ['companyId' => $companyId],
        );
    }

    private function hasLockedRows(string $companyId, bool $restore, \DateTimeImmutable $lock): bool
    {
        // Не SELECT EXISTS(...): pdo_pgsql отдаёт boolean строками 't'/'f', а (bool) 'f'
        // в PHP — true, то есть проверка была бы всегда «замок есть». Проба SELECT 1
        // возвращает false при отсутствии строки — тот же паттерн, что в
        // DealNumberGenerator::numberExists().
        $row = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM cash_transaction
             WHERE company_id = :companyId
               AND deleted_at IS '.($restore ? 'NOT NULL' : 'NULL').'
               AND occurred_at < :lock
             LIMIT 1',
            ['companyId' => $companyId, 'lock' => $lock->format('Y-m-d')],
        );

        return false !== $row;
    }

    private function minOccurredAt(string $companyId, bool $restore): ?\DateTimeImmutable
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT min(occurred_at) FROM cash_transaction
             WHERE company_id = :companyId AND deleted_at IS '.($restore ? 'NOT NULL' : 'NULL'),
            ['companyId' => $companyId],
        );

        return false === $value || null === $value ? null : new \DateTimeImmutable((string) $value);
    }
}
