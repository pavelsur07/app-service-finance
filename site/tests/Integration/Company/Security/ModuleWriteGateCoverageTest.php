<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Security;

use App\Company\Security\Module;
use App\Company\Security\ModuleAccessMap;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Каждый мутирующий маршрут в модулях с расставленными write-гейтами обязан быть закрыт,
 * причём гейтом именно своего модуля.
 *
 * Инвариант держит расстановку Stage 3–4 от тихой эрозии. Строится от скомпилированной
 * RouteCollection, а не от атрибутов методов: у invokable-контроллеров `#[Route]` висит
 * на классе, и проверка «по атрибутам метода» их бы молча пропускала.
 *
 * Маршрут без явного `methods` принимает и POST, поэтому считается потенциально мутирующим.
 * Проверенные вручную read-страницы перечислены в READ_ONLY_ANY_ROUTES по имени маршрута —
 * список, а не эвристика, чтобы новый ANY-маршрут не проскочил.
 */
final class ModuleWriteGateCoverageTest extends KernelTestCase
{
    /** Модули, в которых write-гейты расставлены (Stage 3–4). */
    private const COVERED_PREFIXES = [
        'App\\Cash\\Controller\\',
        'App\\Finance\\',
        'App\\Balance\\',
        'App\\Report\\',
        'App\\Loan\\',
        'App\\Ai\\',
        'App\\Deals\\',
        'App\\Catalog\\',
        'App\\Marketplace\\',
        'App\\MarketplaceAds\\',
        'App\\MarketplaceAnalytics\\',
        'App\\Inventory\\',
        'App\\Ingestion\\',
        'App\\MoySklad\\',
    ];

    /** Свои гейты (ROLE_ADMIN / ROLE_SUPER_ADMIN), модульные к ним не применяются. */
    private const EXCLUDED_PREFIXES = [
        'App\\Marketplace\\Controller\\Admin\\',
        'App\\MarketplaceAds\\Controller\\Api\\Admin\\',
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Маршруты без явного `methods`, проверенные поимённо как read-страницы
     * (render или redirect, без мутаций). Инвентаризация Stage 4.
     *
     * @var list<string>
     */
    private const READ_ONLY_ANY_ROUTES = [
        'integrations_marketplace_index',
        'marketplace_connections_index',
        'marketplace_index',
        'marketplace_raw_view',
        'marketplace_costs_index',
        'marketplace_products_index',
        'marketplace_cost_categories_index',
        'marketplace_returns_index',
        'marketplace_pl_mappings_index',
        'marketplace_sales_index',
        // Финансовые read-страницы без явного methods (инвентаризация Stage 4).
        'app_ai_suggestions_index',
        'finance_report_pl_raw',
    ];

    /** Более строгие гейты: сравниваются точно, а не подстрокой. */
    private const STRICTER_ATTRIBUTES = ['ROLE_COMPANY_OWNER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** Точные формы owner-проверки в теле экшена. */
    private const OWNER_CALLS = ['assertOwner(', '->isOwner(', 'assertCompanyOwner('];

    public function testEveryMutatingRouteInCoveredModulesIsGatedByItsOwnModule(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $map = new ModuleAccessMap();

        $problems = [];
        $checked = 0;

        foreach ($router->getRouteCollection() as $routeName => $route) {
            $controller = (string) ($route->getDefaults()['_controller'] ?? '');
            if ('' === $controller) {
                continue;
            }

            [$className, $methodName] = $this->splitController($controller);
            if (!$this->isCovered($className) || !class_exists($className)) {
                continue;
            }

            $module = $map->resolve($className);
            if (null === $module) {
                // Контроллер покрытого модуля обязан быть классифицирован: без записи в карте
                // fail-closed подписчик отдаст 403, а write-гейт вообще нельзя выбрать.
                $problems[] = sprintf('%s (%s) — не классифицирован в ModuleAccessMap', $routeName, $className);
                continue;
            }

            $methods = $route->getMethods();
            $isMutating = [] === $methods
                ? !\in_array($routeName, self::READ_ONLY_ANY_ROUTES, true)
                : [] !== array_intersect($methods, self::MUTATING_METHODS);

            if (!$isMutating) {
                continue;
            }

            ++$checked;

            if (!$this->isGated($className, $methodName, $module)) {
                $problems[] = sprintf(
                    '%s (%s::%s) — нет гейта %s',
                    $routeName,
                    $className,
                    $methodName,
                    $this->writeConstant($module),
                );
            }
        }

        // Факт на 2026-08-11: 133 мутирующих маршрута в покрытых модулях. Нижняя граница
        // с запасом против «тихого» зануления обхода RouteCollection.
        self::assertGreaterThan(90, $checked, 'Мутирующие маршруты не найдены — проверьте обход RouteCollection.');
        self::assertSame([], $problems, "Мутирующие маршруты без корректного write-гейта:\n".implode("\n", $problems));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitController(string $controller): array
    {
        if (str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);

            return [$class, $method];
        }

        return [$controller, '__invoke'];
    }

    private function isCovered(string $className): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return false;
            }
        }

        foreach (self::COVERED_PREFIXES as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function writeConstant(Module $module): string
    {
        return 'ModuleAccess::'.strtoupper($module->value).'_WRITE';
    }

    private function isGated(string $className, string $methodName, Module $module): bool
    {
        $reflection = new \ReflectionClass($className);
        if (!$reflection->hasMethod($methodName)) {
            return false;
        }

        $method = $reflection->getMethod($methodName);
        $expected = 'module.'.$module->value.'.write';

        foreach ($method->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $value = $this->attributeValue($attribute);
            if ($expected === $value || \in_array($value, self::STRICTER_ATTRIBUTES, true)) {
                return true;
            }
        }

        // Класс целиком под более строгим гейтом.
        foreach ($reflection->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            if (\in_array($this->attributeValue($attribute), self::STRICTER_ATTRIBUTES, true)) {
                return true;
            }
        }

        return $this->bodyHasGate($method, $module);
    }

    private function attributeValue(\ReflectionAttribute $attribute): string
    {
        $args = $attribute->getArguments();

        return (string) ($args[0] ?? $args['attribute'] ?? '');
    }

    /**
     * Смешанные GET+POST экшены гейтятся в теле. Ищем точную форму вызова с константой
     * своего модуля — подстрочное совпадение вида «упоминание ModuleAccess» не годится.
     */
    private function bodyHasGate(\ReflectionMethod $method, Module $module): bool
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (false === $file || false === $start || false === $end) {
            return false;
        }

        $lines = explode("\n", (string) file_get_contents($file));
        $body = implode("\n", \array_slice($lines, $start - 1, $end - $start + 1));

        if (str_contains($body, 'denyAccessUnlessGranted('.$this->writeConstant($module).')')) {
            return true;
        }

        foreach (self::OWNER_CALLS as $call) {
            if (str_contains($body, $call)) {
                return true;
            }
        }

        return false;
    }
}
