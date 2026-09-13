<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Domain\ApiScopeCatalog;
use App\Api\Domain\ApiScopePolicy;
use App\Api\Exception\InvalidApiKeyPermissionsException;
use App\Tests\Builders\Api\ApiKeyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiScopePolicyTest extends TestCase
{
    public function testCatalogContainsOnlyExplicitAllowedScopesAndEverythingIsDisconnected(): void
    {
        self::assertCount(10, ApiScopeCatalog::resources());
        self::assertCount(16, ApiScopeCatalog::scopes());
        self::assertSame([], ApiScopeCatalog::connectedResources());
        foreach (['accounts', 'counterparties', 'cash_categories', 'pl_categories', 'projects', 'responsibility_centers', 'cashflow_reports', 'pl_reports'] as $resource) {
            self::assertSame(['read'], ApiScopeCatalog::resources()[$resource]['actions']);
        }
        foreach (['cash_transactions', 'pl_operations'] as $resource) {
            self::assertSame(['read', 'create', 'update', 'soft_delete'], ApiScopeCatalog::resources()[$resource]['actions']);
        }
        foreach (ApiScopeCatalog::presets() as $preset) {
            self::assertSame([], array_diff($preset, ApiScopeCatalog::scopes()));
            self::assertNotContains('*', $preset);
        }
    }

    public function testSelectedConnectedAndExplicitlyEnabledAreAllRequired(): void
    {
        $selected = ['accounts.read', 'cash_transactions.create', 'pl_operations.soft_delete'];
        self::assertSame([], ApiScopePolicy::effectiveScopes($selected, [], ['accounts', 'cash_transactions']));
        self::assertSame([], ApiScopePolicy::effectiveScopes($selected, ['accounts'], []));
        self::assertSame(['accounts.read'], ApiScopePolicy::effectiveScopes($selected, ['accounts', 'projects'], ['accounts', 'projects']));
        self::assertSame(['cash_transactions.create'], ApiScopePolicy::effectiveScopes($selected, ['cash_transactions'], ['cash_transactions']));
    }

    public function testScopeActionsAreIndependentAndUnknownPersistedValuesFailClosed(): void
    {
        self::assertSame(['cash_transactions.create'], ApiScopePolicy::effectiveScopes(['cash_transactions.create', 'cash_transactions.destroy', '*', 'unknown.read'], ['cash_transactions', 'unknown'], ['cash_transactions', 'unknown']));
        self::assertSame([], ApiScopePolicy::effectiveScopes(['accounts.create'], ['accounts'], ['accounts']));
    }

    public function testConnectingPreparedKeyDoesNotEnableItAndNewScopesAreNotGranted(): void
    {
        $key = ApiKeyBuilder::aKey()->build();
        self::assertSame([], $key->getSelectedScopes());
        self::assertSame([], $key->getEnabledResources());
        $key->setPermissions(['cash_transactions.read'], []);
        self::assertSame([], ApiScopePolicy::effectiveScopes($key->getSelectedScopes(), $key->getEnabledResources(), ['cash_transactions']));
        $key->setPermissions($key->getSelectedScopes(), ['cash_transactions']);
        self::assertSame(['cash_transactions.read'], ApiScopePolicy::effectiveScopes($key->getSelectedScopes(), $key->getEnabledResources(), ['cash_transactions']));
    }

    public function testNormalizationSortsAndDeduplicates(): void
    {
        self::assertSame(['accounts.read', 'projects.read'], ApiScopeCatalog::normalizeScopes(['projects.read', 'accounts.read', 'projects.read']));
        self::assertSame(['accounts', 'projects'], ApiScopeCatalog::normalizeResources(['projects', 'accounts', 'projects']));
    }

    /** @return iterable<array{array<mixed>}> */
    public static function invalidScopes(): iterable
    {
        yield [['*']];
        yield [['accounts.*']];
        yield [['accounts.create']];
        yield [['future.read']];
        yield [[7]];
        yield [[['accounts.read']]];
    }

    /** @param array<mixed> $scopes */
    #[DataProvider('invalidScopes')]
    public function testUnknownOrMalformedScopesAreRejected(array $scopes): void
    {
        $this->expectException(InvalidApiKeyPermissionsException::class);
        ApiScopeCatalog::normalizeScopes($scopes);
    }

    public function testUnknownResourceIsRejected(): void
    {
        $this->expectException(InvalidApiKeyPermissionsException::class);
        ApiScopeCatalog::normalizeResources(['unknown']);
    }
}
