<?php

declare(strict_types=1);

namespace App\Api\Domain;

final class ApiScopePolicy
{
    /**
     * @param list<string> $selectedScopes
     * @param list<string> $enabledResources
     * @param list<string> $connectedResources trusted server capabilities, never request input
     *
     * @return list<string>
     */
    public static function effectiveScopes(array $selectedScopes, array $enabledResources, array $connectedResources): array
    {
        $effective = [];
        foreach (ApiScopeCatalog::scopes() as $scope) {
            $resource = explode('.', $scope, 2)[0];
            if (in_array($scope, $selectedScopes, true) && in_array($resource, $enabledResources, true) && in_array($resource, $connectedResources, true)) {
                $effective[] = $scope;
            }
        }
        sort($effective, SORT_STRING);

        return $effective;
    }
}
