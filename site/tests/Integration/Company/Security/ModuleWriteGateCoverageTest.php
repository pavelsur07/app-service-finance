<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Security;

use App\Company\Security\Module;
use App\Company\Security\ModuleAccessMap;
use App\Company\Security\PublicAccess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Каждый мутирующий маршрут в модулях с расставленными write-гейтами обязан быть закрыт,
 * причём гейтом именно своего модуля.
 *
 * Инвариант держит расстановку Stage 3–4 от тихой эрозии. Устройство выбрано так, чтобы
 * не иметь fail-open путей:
 *
 * - обход идёт по скомпилированной RouteCollection, а не по атрибутам методов: у invokable
 *   контроллеров `#[Route]` висит на классе, и проверка «по методу» их бы пропускала;
 * - покрытие определяется через ModuleAccessMap, а не вторым списком неймспейсов, который
 *   разъехался бы с картой. Неклассифицированный контроллер — падение, а не пропуск;
 * - exempt-зоны и делегированные owner-проверки не пропускаются по классу: каждый такой
 *   мутирующий маршрут перечислен в ROUTE_POLICY поимённо с указанием, чем именно он закрыт.
 *   Новый мутирующий маршрут в exempt-классе роняет тест, пока его не классифицируют;
 * - ROUTE_POLICY проверяется и на устаревание: запись про исчезнувший маршрут тоже падение;
 * - `#[PublicAccess]` признаётся явным осознанным исключением: это машинно-проверяемый атрибут,
 *   а не догадка;
 * - тело экшена читается по PHP-токенам без комментариев и строк, чтобы закомментированный
 *   гейт не считался гейтом;
 * - маршрут без явного `methods` считается мутирующим без исключений: read-страницы обязаны
 *   объявлять `methods: ['GET']`, тогда read-only держит сам роутер, а не список в тесте;
 * - счётчики ведутся по каждому модулю, поэтому потеря целой группы не спрячется за общим порогом.
 */
final class ModuleWriteGateCoverageTest extends KernelTestCase
{
    /** Модули, мутации которых обязаны быть закрыты (Stage 3 — четыре группы, Stage 4 — marketplace). */
    private const COVERED_MODULES = ['finance', 'deals', 'catalog', 'admin', 'marketplace'];

    /**
     * Мутирующие маршруты, закрытые не модульным write-гейтом. Перечислены поимённо с политикой,
     * потому что статический обход не видит ни firewall access_control, ни owner-проверку,
     * выполненную внутри Action.
     *
     * `delegated-owner` — экшен тонкий, владельца сверяет Action (например DisableCompanyMemberAction).
     * `inline-owner`    — владелец сверяется прямо в теле сравнением с $company->getUser().
     * `authenticated-self` — личная настройка пользователя, компания не при чём.
     * `firewall`        — доступ ограничен access_control в security.yaml (админка).
     *
     * @var array<string, string>
     */
    private const ROUTE_POLICY = [
        // Управление участниками: владельца сверяет Action, экшен только маршрутизирует.
        'company_member_disable' => 'delegated-owner',
        'company_member_enable' => 'delegated-owner',
        'company_member_disable_legacy' => 'delegated-owner',
        'company_member_enable_legacy' => 'delegated-owner',
        'company_users_invite_legacy' => 'delegated-owner',
        'company_invite_revoke_legacy' => 'delegated-owner',
        'company_member_access_role_legacy' => 'delegated-owner',
        // Список и удаление компаний: сверка владельца инлайном, работает и без активной компании.
        'company_new' => 'inline-owner',
        'company_edit' => 'inline-owner',
        'company_delete' => 'inline-owner',
        'company_set_active' => 'inline-owner',
        // Личные настройки пользователя.
        'app_profile_password' => 'authenticated-self',
        'app_ui_mode_switch' => 'authenticated-self',
        // Админка: отдельный firewall и access_control.
        'admin_auth_login' => 'firewall',
        'admin_user_create_account' => 'firewall',
        'admin_user_update_roles' => 'firewall',
        'admin_telegram_bot_new' => 'firewall',
        'admin_telegram_bot_edit' => 'firewall',
        'admin_telegram_bot_toggle' => 'firewall',
        'admin_telegram_bot_webhook_set' => 'firewall',
        'admin_ingestion_external_categories_discover' => 'firewall',
        'admin_ingestion_external_categories_refresh_ozon_metadata' => 'firewall',
        'admin_ingestion_external_categories_seed_defaults' => 'firewall',
        'admin_ingestion_external_categories_update_mapping' => 'firewall',
        'admin_marketplace_mapping_error_resolve' => 'firewall',
        'marketplace_ads_admin_mark_load_job_failed' => 'firewall',
    ];

    /** Минимум мутирующих маршрутов на модуль — против «тихого» зануления обхода. */
    private const MIN_MUTATING_PER_MODULE = [
        'finance' => 40,
        'marketplace' => 50,
        'deals' => 5,
        'catalog' => 3,
        'admin' => 5,
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Гейты строже модульного. Сравниваются точно: `ROLE_ADMIN` не должен совпадать
     * с гипотетическим `ROLE_ADMIN_SOMETHING`.
     */
    private const STRICTER_ATTRIBUTES = ['ROLE_COMPANY_OWNER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /**
     * Единственная допустимая форма owner-проверки в теле: она бросает AccessDenied.
     * Предикаты вида `isOwner()` сюда не входят — их результат можно проигнорировать.
     */
    private const OWNER_CALL = 'assertOwner(';

    public function testEveryMutatingRouteInCoveredModulesIsGatedByItsOwnModule(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $map = new ModuleAccessMap();

        $problems = [];
        $perModule = array_fill_keys(self::COVERED_MODULES, 0);
        $mutatingByClass = [];
        $readByClass = [];
        $usedPolicies = [];

        foreach ($router->getRouteCollection() as $routeName => $route) {
            $controller = (string) ($route->getDefaults()['_controller'] ?? '');
            if ('' === $controller || !str_starts_with($controller, 'App\\')) {
                continue;
            }

            [$className, $methodName] = $this->splitController($controller);
            if (!class_exists($className)) {
                continue;
            }

            $isMutating = $this->isMutatingRoute($route);
            $exempt = $map->isExempt($className);
            $module = $map->resolve($className);

            if (!$exempt && (null === $module || !\in_array($module->value, self::COVERED_MODULES, true))) {
                continue;
            }

            if ($isMutating && $this->hasPublicAccess($className, $methodName)) {
                // Явное осознанное исключение: логин, регистрация, приём инвайта, вебхуки.
                $usedPolicies[$routeName] = 'public-access';

                continue;
            }

            if ($isMutating && isset(self::ROUTE_POLICY[$routeName])) {
                $usedPolicies[$routeName] = self::ROUTE_POLICY[$routeName];

                continue;
            }

            if ($exempt) {
                if ($isMutating) {
                    $problems[] = sprintf('%s (%s::%s) — мутирующий маршрут в exempt-зоне без записи в ROUTE_POLICY', $routeName, $className, $methodName);
                }

                continue;
            }

            \assert($module instanceof Module);

            if ($isMutating) {
                $mutatingByClass[$className][] = $routeName;
            } else {
                $readByClass[$className][] = $routeName;

                continue;
            }

            ++$perModule[$module->value];

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

        // Class-level write-гейт на классе, у которого есть и read-маршруты, отрезал бы чтение.
        foreach (array_keys($mutatingByClass) as $className) {
            if (!isset($readByClass[$className])) {
                continue;
            }
            if ($this->hasClassLevelWriteGate($className)) {
                $problems[] = sprintf('%s — class-level write-гейт отрезает read-маршруты %s', $className, implode(', ', $readByClass[$className]));
            }
        }

        // Устаревшая запись в карте политик так же опасна, как отсутствующая: она бы молча
        // разрешала маршрут, которого уже нет, и маскировала переименование.
        $stale = array_diff(array_keys(self::ROUTE_POLICY), array_keys($usedPolicies));
        self::assertSame([], array_values($stale), 'ROUTE_POLICY описывает маршруты, которых больше нет: '.implode(', ', $stale));

        foreach (self::MIN_MUTATING_PER_MODULE as $moduleValue => $minimum) {
            self::assertGreaterThanOrEqual(
                $minimum,
                $perModule[$moduleValue],
                sprintf('Модуль %s: найдено %d мутирующих маршрутов, ожидалось не меньше %d — проверьте обход RouteCollection.', $moduleValue, $perModule[$moduleValue], $minimum),
            );
        }

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

    private function isMutatingRoute(\Symfony\Component\Routing\Route $route): bool
    {
        $methods = $route->getMethods();

        // Пустой список — маршрут принимает всё, включая POST. Read-страница обязана
        // объявить methods явно, иначе read-only держится только на честном слове.
        return [] === $methods || [] !== array_intersect($methods, self::MUTATING_METHODS);
    }

    private function hasPublicAccess(string $className, string $methodName): bool
    {
        $reflection = new \ReflectionClass($className);

        if ([] !== $reflection->getAttributes(PublicAccess::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        return $reflection->hasMethod($methodName)
            && [] !== $reflection->getMethod($methodName)->getAttributes(PublicAccess::class, \ReflectionAttribute::IS_INSTANCEOF);
    }

    private function writeConstant(Module $module): string
    {
        return 'ModuleAccess::'.strtoupper($module->value).'_WRITE';
    }

    private function isGated(string $className, string $methodName, Module $module): bool
    {
        $method = $this->method($className, $methodName);
        if (null === $method) {
            return false;
        }

        $expected = 'module.'.$module->value.'.write';

        foreach ($this->grantedAttributes($method) as $value) {
            if ($expected === $value || \in_array($value, self::STRICTER_ATTRIBUTES, true)) {
                return true;
            }
        }

        foreach ($this->grantedAttributes(new \ReflectionClass($className)) as $value) {
            if ($expected === $value || \in_array($value, self::STRICTER_ATTRIBUTES, true)) {
                return true;
            }
        }

        return $this->bodyHasGate($method, $module);
    }

    private function hasClassLevelWriteGate(string $className): bool
    {
        foreach ($this->grantedAttributes(new \ReflectionClass($className)) as $value) {
            if (str_starts_with($value, 'module.') && str_ends_with($value, '.write')) {
                return true;
            }
        }

        return false;
    }

    private function method(string $className, string $methodName): ?\ReflectionMethod
    {
        $reflection = new \ReflectionClass($className);

        return $reflection->hasMethod($methodName) ? $reflection->getMethod($methodName) : null;
    }

    /**
     * @return list<string>
     */
    private function grantedAttributes(\ReflectionClass|\ReflectionMethod $target): array
    {
        $values = [];
        foreach ($target->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $args = $attribute->getArguments();
            $values[] = (string) ($args[0] ?? $args['attribute'] ?? '');
        }

        return $values;
    }

    private function bodyHasGate(\ReflectionMethod $method, Module $module): bool
    {
        $body = $this->executableBody($method);

        return str_contains($body, 'denyAccessUnlessGranted('.$this->writeConstant($module).')')
            || str_contains($body, self::OWNER_CALL);
    }

    /**
     * Тело метода без комментариев и строковых литералов: закомментированный или
     * упомянутый в строке гейт гейтом не является.
     */
    private function executableBody(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (false === $file || false === $start || false === $end) {
            return '';
        }

        $lines = explode("\n", (string) file_get_contents($file));
        $source = implode("\n", \array_slice($lines, $start - 1, $end - $start + 1));

        $out = '';
        foreach (token_get_all('<?php '.$source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
