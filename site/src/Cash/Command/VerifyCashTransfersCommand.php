<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:verify-transfers',
    description: 'Проверяет целостность агрегатов переводов ДДС без изменения данных',
)]
final class VerifyCashTransfersCommand extends Command
{
    private const COMPANY_BATCH_SIZE = 100;

    /** @var array<string, string> */
    private const CHECK_TITLES = [
        'pair_shape' => 'Обе разные ноги существуют',
        'company_scope' => 'Агрегат, ноги и счета одной компании',
        'account_contract' => 'Разные fiat-счета, дата не раньше открытия',
        'leg_contract' => 'OUTFLOW/INFLOW, дата, флаг и сумма ног',
        'currency_contract' => 'Валюты ног совпадают со счетами и парой v1',
        'technical_splits' => 'По одной системной CF_TECH_OUT/CF_TECH_IN строке',
        'same_currency_amount' => 'Равные суммы перевода в одной валюте',
        'fx_metadata' => 'Полный и точный effective-rate контракт',
        'deletion_state' => 'Агрегат и обе ноги удалены/активны вместе',
        'idempotency_key' => 'Непустой нормализованный idempotency key',
        'idempotency_duplicates' => 'Нет повторов company + idempotency key',
        'leg_ownership' => 'Каждая нога принадлежит одному агрегату',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = array_fill_keys(array_keys(self::CHECK_TITLES), 0);
        $companies = 0;
        $transfers = 0;
        $legacyLegs = 0;

        foreach ($this->companyBatches() as $companyIds) {
            $companies += count($companyIds);
            $batch = $this->verifyBatch($companyIds);
            $transfers += $batch['transfer_count'];
            $legacyLegs += $this->countLegacyLegs($companyIds);

            foreach (array_keys(self::CHECK_TITLES) as $key) {
                if (array_key_exists($key, $batch)) {
                    $counts[$key] += $batch[$key];
                }
            }
        }

        $counts['idempotency_duplicates'] = $this->countIdempotencyDuplicates();
        $counts['leg_ownership'] = $this->countReusedLegs();

        $rows = [];
        $failed = false;
        foreach (self::CHECK_TITLES as $key => $title) {
            $count = $counts[$key];
            $failed = $failed || $count > 0;
            $rows[] = [$key, $title, $count, 0 === $count ? 'OK' : 'FAIL'];
        }

        $io->writeln(sprintf('Проверено компаний: %d; агрегатов: %d.', $companies, $transfers));
        $io->table(['Проверка', 'Что означает', 'Нарушений', 'Статус'], $rows);
        $io->writeln(sprintf(
            'Legacy isTransfer=true без агрегата: %d (INFO, не является ошибкой).',
            $legacyLegs,
        ));

        if ($failed) {
            $io->error('Сверка переводов не пройдена. Команда ничего не изменила.');

            return Command::FAILURE;
        }

        $io->success('Сверка переводов пройдена.');

        return Command::SUCCESS;
    }

    /** @return iterable<list<string>> */
    private function companyBatches(): iterable
    {
        $after = null;

        do {
            $where = '';
            $parameters = [];
            if (null !== $after) {
                $where = 'WHERE company_id > CAST(:after AS UUID)';
                $parameters['after'] = $after;
            }

            $companyIds = array_map(
                static fn (mixed $id): string => (string) $id,
                $this->connection->fetchFirstColumn(sprintf(
                    <<<'SQL'
                        SELECT company_id::text
                        FROM (
                            SELECT company_id FROM cash_transfer
                            UNION
                            SELECT company_id FROM cash_transaction WHERE is_transfer = TRUE
                        ) scope
                        %s
                        ORDER BY company_id
                        LIMIT %d
                        SQL,
                    $where,
                    self::COMPANY_BATCH_SIZE,
                ), $parameters),
            );

            if ([] === $companyIds) {
                return;
            }

            yield $companyIds;
            $after = $companyIds[array_key_last($companyIds)];
        } while (self::COMPANY_BATCH_SIZE === count($companyIds));
    }

    /**
     * @param list<string> $companyIds
     *
     * @return array<string, int>
     */
    private function verifyBatch(array $companyIds): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH transfer_rows AS (
                    SELECT
                        tr.*,
                        source.id AS source_id,
                        source.company_id AS source_company_id,
                        source.money_account_id AS source_account_id,
                        source.cashflow_category_id AS source_category_id,
                        source.direction AS source_direction,
                        source.amount AS source_amount,
                        source.currency AS source_currency,
                        source.occurred_at AS source_occurred_at,
                        source.is_transfer AS source_is_transfer,
                        source.deleted_at AS source_deleted_at,
                        source.deleted_by AS source_deleted_by,
                        source.delete_reason AS source_delete_reason,
                        target.id AS target_id,
                        target.company_id AS target_company_id,
                        target.money_account_id AS target_account_id,
                        target.cashflow_category_id AS target_category_id,
                        target.direction AS target_direction,
                        target.amount AS target_amount,
                        target.currency AS target_currency,
                        target.occurred_at AS target_occurred_at,
                        target.is_transfer AS target_is_transfer,
                        target.deleted_at AS target_deleted_at,
                        target.deleted_by AS target_deleted_by,
                        target.delete_reason AS target_delete_reason,
                        source_account.id AS source_account_row_id,
                        source_account.company_id AS source_account_company_id,
                        source_account.currency AS source_account_currency,
                        source_account.type AS source_account_type,
                        source_account.opening_balance_date AS source_account_opening_date,
                        target_account.id AS target_account_row_id,
                        target_account.company_id AS target_account_company_id,
                        target_account.currency AS target_account_currency,
                        target_account.type AS target_account_type,
                        target_account.opening_balance_date AS target_account_opening_date,
                        (SELECT count(*) FROM cash_transaction_split split WHERE split.cash_transaction_id = source.id) AS source_split_count,
                        (SELECT count(*)
                         FROM cash_transaction_split split
                         JOIN cashflow_categories category ON category.id = split.cashflow_category_id
                         LEFT JOIN cashflow_categories parent ON parent.id = category.parent_id
                         WHERE split.cash_transaction_id = source.id
                           AND split.company_id = tr.company_id
                           AND category.company_id = tr.company_id
                           AND category.id = source.cashflow_category_id
                           AND category.system_code = :sourceCategoryCode
                           AND category.is_system = TRUE
                           AND category.status = :activeStatus
                           AND category.flow_kind = :technicalFlow
                           AND parent.company_id = tr.company_id
                           AND parent.system_code = :technicalRootCode
                           AND parent.is_system = TRUE
                           AND parent.status = :activeStatus
                           AND parent.flow_kind = :technicalFlow
                           AND split.amount = source.amount) AS valid_source_split_count,
                        (SELECT count(*) FROM cash_transaction_split split WHERE split.cash_transaction_id = target.id) AS target_split_count,
                        (SELECT count(*)
                         FROM cash_transaction_split split
                         JOIN cashflow_categories category ON category.id = split.cashflow_category_id
                         LEFT JOIN cashflow_categories parent ON parent.id = category.parent_id
                         WHERE split.cash_transaction_id = target.id
                           AND split.company_id = tr.company_id
                           AND category.company_id = tr.company_id
                           AND category.id = target.cashflow_category_id
                           AND category.system_code = :targetCategoryCode
                           AND category.is_system = TRUE
                           AND category.status = :activeStatus
                           AND category.flow_kind = :technicalFlow
                           AND parent.company_id = tr.company_id
                           AND parent.system_code = :technicalRootCode
                           AND parent.is_system = TRUE
                           AND parent.status = :activeStatus
                           AND parent.flow_kind = :technicalFlow
                           AND split.amount = target.amount) AS valid_target_split_count
                    FROM cash_transfer tr
                    LEFT JOIN cash_transaction source ON source.id = tr.source_transaction_id
                    LEFT JOIN cash_transaction target ON target.id = tr.target_transaction_id
                    LEFT JOIN money_account source_account ON source_account.id = source.money_account_id
                    LEFT JOIN money_account target_account ON target_account.id = target.money_account_id
                    WHERE tr.company_id IN (:companyIds)
                )
                SELECT
                    count(*) AS transfer_count,
                    count(*) FILTER (WHERE source_id IS NULL OR target_id IS NULL OR source_transaction_id = target_transaction_id) AS pair_shape,
                    count(*) FILTER (WHERE source_company_id IS DISTINCT FROM company_id
                        OR target_company_id IS DISTINCT FROM company_id
                        OR source_account_company_id IS DISTINCT FROM company_id
                        OR target_account_company_id IS DISTINCT FROM company_id) AS company_scope,
                    count(*) FILTER (WHERE source_account_row_id IS NULL OR target_account_row_id IS NULL
                        OR source_account_id = target_account_id
                        OR source_account_type NOT IN (:fiatAccountTypes)
                        OR target_account_type NOT IN (:fiatAccountTypes)
                        OR source_occurred_at < source_account_opening_date
                        OR target_occurred_at < target_account_opening_date) AS account_contract,
                    count(*) FILTER (WHERE source_direction IS DISTINCT FROM :sourceDirection
                        OR target_direction IS DISTINCT FROM :targetDirection
                        OR source_is_transfer IS DISTINCT FROM TRUE OR target_is_transfer IS DISTINCT FROM TRUE
                        OR source_occurred_at IS DISTINCT FROM target_occurred_at
                        OR source_amount <= 0 OR target_amount <= 0) AS leg_contract,
                    count(*) FILTER (WHERE source_currency IS NULL OR target_currency IS NULL
                        OR source_currency NOT IN (:fiatCurrencies) OR target_currency NOT IN (:fiatCurrencies)
                        OR source_currency IS DISTINCT FROM source_account_currency
                        OR target_currency IS DISTINCT FROM target_account_currency
                        OR NOT (source_currency = target_currency
                            OR (source_currency = :rubCurrency AND target_currency IN (:crossCurrencies))
                            OR (target_currency = :rubCurrency AND source_currency IN (:crossCurrencies)))) AS currency_contract,
                    count(*) FILTER (WHERE source_split_count <> 1 OR valid_source_split_count <> 1
                        OR target_split_count <> 1 OR valid_target_split_count <> 1) AS technical_splits,
                    count(*) FILTER (WHERE source_currency = target_currency AND source_amount IS DISTINCT FROM target_amount) AS same_currency_amount,
                    count(*) FILTER (WHERE CASE
                        WHEN source_currency = target_currency THEN
                            effective_rate IS NOT NULL OR rate_base_currency IS NOT NULL
                            OR rate_quote_currency IS NOT NULL OR rate_date IS NOT NULL OR rate_source IS NOT NULL
                        ELSE
                            effective_rate IS NULL OR effective_rate <= 0
                            OR rate_base_currency IS DISTINCT FROM source_currency
                            OR rate_quote_currency IS DISTINCT FROM target_currency
                            OR rate_date IS DISTINCT FROM source_occurred_at
                            OR rate_source IS DISTINCT FROM :rateSource
                            OR effective_rate IS DISTINCT FROM round(
                                target_amount::numeric(38, 19) / NULLIF(source_amount, 0),
                                18
                            )
                        END) AS fx_metadata,
                    count(*) FILTER (WHERE (deleted_at IS NULL) IS DISTINCT FROM (source_deleted_at IS NULL)
                        OR (deleted_at IS NULL) IS DISTINCT FROM (target_deleted_at IS NULL)
                        OR deleted_by IS DISTINCT FROM source_deleted_by
                        OR deleted_by IS DISTINCT FROM target_deleted_by
                        OR delete_reason IS DISTINCT FROM source_delete_reason
                        OR delete_reason IS DISTINCT FROM target_delete_reason) AS deletion_state,
                    count(*) FILTER (WHERE idempotency_key = '' OR idempotency_key <> btrim(idempotency_key)) AS idempotency_key
                FROM transfer_rows
                SQL,
            [
                'companyIds' => $companyIds,
                'sourceCategoryCode' => CashflowCategory::CODE_TECHNICAL_OUT,
                'targetCategoryCode' => CashflowCategory::CODE_TECHNICAL_IN,
                'technicalRootCode' => CashflowCategory::CODE_TECHNICAL,
                'activeStatus' => CashflowCategoryStatus::ACTIVE->value,
                'technicalFlow' => CashflowFlowKind::TECHNICAL->value,
                'fiatAccountTypes' => [
                    MoneyAccountType::BANK->value,
                    MoneyAccountType::CASH->value,
                    MoneyAccountType::EWALLET->value,
                ],
                'sourceDirection' => CashDirection::OUTFLOW->value,
                'targetDirection' => CashDirection::INFLOW->value,
                'fiatCurrencies' => FiatCurrency::values(),
                'rubCurrency' => FiatCurrency::RUB->value,
                'crossCurrencies' => [FiatCurrency::USD->value, FiatCurrency::EUR->value],
                'rateSource' => CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE,
            ],
            [
                'companyIds' => ArrayParameterType::STRING,
                'fiatAccountTypes' => ArrayParameterType::STRING,
                'fiatCurrencies' => ArrayParameterType::STRING,
                'crossCurrencies' => ArrayParameterType::STRING,
            ],
        );

        if (false === $row) {
            throw new \RuntimeException('Не удалось прочитать результат сверки переводов.');
        }

        return array_map(static fn (mixed $value): int => (int) $value, $row);
    }

    /** @param list<string> $companyIds */
    private function countLegacyLegs(array $companyIds): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM cash_transaction tx
                WHERE tx.company_id IN (:companyIds)
                  AND tx.is_transfer = TRUE
                  AND NOT EXISTS (
                      SELECT 1 FROM cash_transfer transfer
                      WHERE transfer.source_transaction_id = tx.id
                         OR transfer.target_transaction_id = tx.id
                  )
                SQL,
            ['companyIds' => $companyIds],
            ['companyIds' => ArrayParameterType::STRING],
        );
    }

    private function countIdempotencyDuplicates(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM (
                    SELECT company_id, idempotency_key
                    FROM cash_transfer
                    GROUP BY company_id, idempotency_key
                    HAVING count(*) > 1
                ) duplicates
                SQL,
        );
    }

    private function countReusedLegs(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM (
                    SELECT transaction_id
                    FROM (
                        SELECT source_transaction_id AS transaction_id FROM cash_transfer
                        UNION ALL
                        SELECT target_transaction_id AS transaction_id FROM cash_transfer
                    ) legs
                    GROUP BY transaction_id
                    HAVING count(*) > 1
                ) reused
                SQL,
        );
    }
}
