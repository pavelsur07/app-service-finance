<?php

namespace App\Admin\Service;

use App\Company\Entity\Company;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class UserDeletionService
{
    /**
     * @param Connection $connection Doctrine connection for executing bulk deletes
     * @param EntityManagerInterface $entityManager Doctrine entity manager for transactional removal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function deleteUser(User $user): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user): void {
            $companyIds = $this->collectCompanyIds($user);

            if ([] !== $companyIds) {
                $this->deleteCompanyRelatedData($companyIds);

                /** @var list<Company> $companies */
                $companies = $user->getCompanies()->toArray();

                foreach ($companies as $company) {
                    $user->removeCompany($company);
                    $entityManager->remove($company);
                }
            }

            $this->clearUserReferences($user);

            $entityManager->remove($user);
            $entityManager->flush();
        });
    }

    /**
     * @return list<string>
     */
    private function collectCompanyIds(User $user): array
    {
        /** @var list<Company> $companies */
        $companies = $user->getCompanies()->toArray();

        return array_values(array_filter(array_map(
            static fn (Company $company): ?string => $company->getId(),
            $companies,
        )));
    }

    /**
     * @param list<string> $companyIds
     */
    private function deleteCompanyRelatedData(array $companyIds): void
    {
        $this->deleteFromTables(
            [
                'payment_plan_match',
                'cash_transaction',
                'money_account_daily_balance',
                'payment_plan',
                'payment_recurrence_rule',
                'cash_transaction_auto_rule',
                'documents',
                'import_log',
                'report_api_key',
                'pl_daily_totals',
                'pl_monthly_snapshots',
                'money_fund_movement',
                'money_fund',
                'project_directions',
                'counterparty',
                'cashflow_categories',
                'pl_categories',
                'money_account',
            ],
            $companyIds,
        );
    }

    private function clearUserReferences(User $user): void
    {
        $userId = $user->getId();

        if (null === $userId) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE money_fund_movement SET user_id = NULL WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
    }

    /**
     * @param list<string> $tables
     * @param list<string> $companyIds
     */
    private function deleteFromTables(array $tables, array $companyIds): void
    {
        $platform = $this->connection->getDatabasePlatform();

        foreach ($tables as $table) {
            $sql = sprintf(
                'DELETE FROM %s WHERE company_id IN (:company_ids)',
                $platform->quoteIdentifier($table),
            );

            $this->connection->executeStatement(
                $sql,
                ['company_ids' => $companyIds],
                ['company_ids' => ArrayParameterType::STRING],
            );
        }
    }
}
