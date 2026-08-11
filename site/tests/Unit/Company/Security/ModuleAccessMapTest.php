<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Controller\Api\CounterpartySearchController;
use App\Company\Controller\CounterpartyController;
use App\Company\Controller\InviteController;
use App\Company\Controller\ProfileController;
use App\Company\Controller\ProjectDirectionController;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccessMap;
use App\Shared\Controller\HomeRedirectController;
use App\Shared\Controller\UiModeController;
use PHPUnit\Framework\TestCase;

final class ModuleAccessMapTest extends TestCase
{
    private ModuleAccessMap $map;

    protected function setUp(): void
    {
        $this->map = new ModuleAccessMap();
    }

    public function testExactClassWinsOverPrefix(): void
    {
        // App\Company\Controller\ в целом — admin, но эти контроллеры — finance.
        self::assertSame(Module::FINANCE, $this->map->resolve(CounterpartyController::class));
        self::assertSame(Module::FINANCE, $this->map->resolve(ProjectDirectionController::class));
        self::assertSame(Module::ADMIN, $this->map->resolve(InviteController::class));
    }

    public function testPrefixMapping(): void
    {
        self::assertSame(Module::FINANCE, $this->map->resolve('App\\Cash\\Controller\\Transaction\\CashTransactionController'));
        self::assertSame(Module::FINANCE, $this->map->resolve('App\\Ai\\Controller\\AiSuggestionController'));
        self::assertSame(Module::MARKETPLACE, $this->map->resolve('App\\Marketplace\\Controller\\MarketplaceController'));
        self::assertSame(Module::MARKETPLACE, $this->map->resolve('App\\MarketplaceAds\\Controller\\AdsIndexController'));
        self::assertSame(Module::DEALS, $this->map->resolve('App\\Deals\\Controller\\DealController'));
        self::assertSame(Module::CATALOG, $this->map->resolve('App\\Catalog\\Controller\\ProductIndexController'));
        self::assertSame(Module::ADMIN, $this->map->resolve('App\\Telegram\\Controller\\Integration\\TelegramIntegrationController'));
    }

    public function testUnknownClassResolvesToNull(): void
    {
        self::assertNull($this->map->resolve('App\\Shared\\Controller\\UnknownController'));
        self::assertNull($this->map->resolve('App\\Twig\\SomeExtension'));
    }

    public function testExempt(): void
    {
        self::assertTrue($this->map->isExempt('App\\Admin\\Controller\\DashboardController'));
        self::assertTrue($this->map->isExempt('App\\Analytics\\Controller\\Api\\V1\\DashboardSnapshotController'));
        self::assertTrue($this->map->isExempt('App\\Telegram\\Controller\\Admin\\TelegramBotController'));
        self::assertTrue($this->map->isExempt(ProfileController::class));
        self::assertTrue($this->map->isExempt(UiModeController::class));
        self::assertTrue($this->map->isExempt(HomeRedirectController::class));
        // Общий company-scoped справочник, используется формой сделки (Deals).
        self::assertTrue($this->map->isExempt(CounterpartySearchController::class));

        self::assertFalse($this->map->isExempt(CounterpartyController::class));
        self::assertFalse($this->map->isExempt('App\\Cash\\Controller\\Transaction\\CashTransactionController'));
    }
}
