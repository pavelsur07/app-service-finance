<?php

declare(strict_types=1);

namespace App\Api\Domain;

use App\Api\Exception\InvalidApiKeyPermissionsException;

final class ApiScopeCatalog
{
    /** @return array<string, array{label: string, actions: list<string>, connected: bool}> */
    public static function resources(): array
    {
        return [
            'accounts' => ['label' => 'Счета', 'actions' => ['read'], 'connected' => false],
            'counterparties' => ['label' => 'Контрагенты', 'actions' => ['read'], 'connected' => false],
            'cash_categories' => ['label' => 'Статьи ДДС', 'actions' => ['read'], 'connected' => false],
            'pl_categories' => ['label' => 'Статьи ОПиУ', 'actions' => ['read'], 'connected' => false],
            'projects' => ['label' => 'Проекты', 'actions' => ['read'], 'connected' => false],
            'responsibility_centers' => ['label' => 'Центры финансовой ответственности', 'actions' => ['read'], 'connected' => false],
            'cash_transactions' => ['label' => 'Транзакции ДДС', 'actions' => ['read', 'create', 'update', 'soft_delete'], 'connected' => false],
            'pl_operations' => ['label' => 'Операции ОПиУ', 'actions' => ['read', 'create', 'update', 'soft_delete'], 'connected' => false],
            'cashflow_reports' => ['label' => 'Отчёты ДДС', 'actions' => ['read'], 'connected' => false],
            'pl_reports' => ['label' => 'Отчёты ОПиУ', 'actions' => ['read'], 'connected' => false],
        ];
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        $scopes = [];
        foreach (self::resources() as $resource => $definition) {
            foreach ($definition['actions'] as $action) {
                $scopes[] = $resource.'.'.$action;
            }
        }

        return $scopes;
    }

    /** @return list<string> */
    public static function connectedResources(): array
    {
        return array_keys(array_filter(self::resources(), static fn (array $resource): bool => $resource['connected']));
    }

    /** @return array<string, list<string>> */
    public static function presets(): array
    {
        // Concrete grants, intentionally not a wildcard or a dynamically growing preset.
        return [
            'read_only' => ['accounts.read', 'counterparties.read', 'cash_categories.read', 'pl_categories.read', 'projects.read', 'responsibility_centers.read', 'cash_transactions.read', 'pl_operations.read', 'cashflow_reports.read', 'pl_reports.read'],
            'all_scopes' => ['accounts.read', 'counterparties.read', 'cash_categories.read', 'pl_categories.read', 'projects.read', 'responsibility_centers.read', 'cash_transactions.read', 'cash_transactions.create', 'cash_transactions.update', 'cash_transactions.soft_delete', 'pl_operations.read', 'pl_operations.create', 'pl_operations.update', 'pl_operations.soft_delete', 'cashflow_reports.read', 'pl_reports.read'],
        ];
    }

    /** @param array<mixed> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        return self::normalize($scopes, self::scopes());
    }

    /** @param array<mixed> $resources
     * @return list<string>
     */
    public static function normalizeResources(array $resources): array
    {
        return self::normalize($resources, array_keys(self::resources()));
    }

    /** @param array<mixed> $values
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function normalize(array $values, array $allowed): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidApiKeyPermissionsException();
            }
            $normalized[] = $value;
        }
        $normalized = array_unique($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
