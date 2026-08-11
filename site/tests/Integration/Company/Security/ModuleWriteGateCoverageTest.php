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
 * - гейтом признаётся только точный атрибут `module.<resolved>.write`, точная форма
 *   `denyAccessUnlessGranted(ModuleAccess::<MODULE>_WRITE)` в теле (для смешанных GET+POST,
 *   тело читается по токенам без комментариев и строк) либо запись в ROUTE_POLICY
 *   с совпадающим `Class::method`. Эвристик вида «где-то упомянут assertOwner» нет;
 * - маршрут без явного `methods` считается мутирующим без исключений: read-страницы обязаны
 *   объявлять `methods: ['GET']`, тогда read-only держит сам роутер, а не список в тесте;
 * - счётчики ведутся по каждому модулю, поэтому потеря целой группы не спрячется за общим порогом.
 */
final class ModuleWriteGateCoverageTest extends KernelTestCase
{
    /** Модули, мутации которых обязаны быть закрыты (Stage 3 — четыре группы, Stage 4 — marketplace). */
    private const COVERED_MODULES = ['finance', 'deals', 'catalog', 'admin', 'marketplace'];

    /**
     * Мутирующие маршруты, закрытые не модульным write-гейтом. Каждая запись называет и политику,
     * и ожидаемый `Class::method`, поэтому переназначение имени маршрута на другой контроллер
     * не даёт безусловного пропуска — запись перестанет совпадать и тест упадёт.
     *
     * `owner`             — в теле экшена `assertOwner($company)`: сверка владельца именно активной компании.
     * `delegated-owner`   — экшен тонкий, владельца сверяет Action (DisableCompanyMemberAction и др.).
     * `inline-owner`      — сверка владельца сравнением `$company->getUser()` прямо в теле.
     * `authenticated-self`— личная настройка пользователя, компания не при чём.
     * `firewall`          — доступ ограничен access_control в security.yaml (админка).
     *
     * Негативное поведение этих маршрутов покрыто функционально: CompanyRoleControllerTest
     * (`testNonOwnerCannotAccessRoles`), CompanyMemberAccessRoleTest, CompanyMemberAccessTest.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ROUTE_POLICY = [
        // Шаблоны ролей и участники: owner-only, проверка в теле экшена.
        'company_role_new' => ['owner', 'App\Company\Controller\CompanyRoleController::new'],
        'company_role_create' => ['owner', 'App\Company\Controller\CompanyRoleController::new'],
        'company_role_edit' => ['owner', 'App\Company\Controller\CompanyRoleController::edit'],
        'company_role_update' => ['owner', 'App\Company\Controller\CompanyRoleController::edit'],
        'company_role_delete' => ['owner', 'App\Company\Controller\CompanyRoleController::delete'],
        'company_users_invite' => ['owner', 'App\Company\Controller\CompanyMemberController::invite'],
        'company_invite_revoke' => ['owner', 'App\Company\Controller\CompanyMemberController::revokeInvite'],
        'company_member_access_role' => ['owner', 'App\Company\Controller\CompanyMemberController::setAccessRole'],
        'settings_report_api_key_generate' => ['owner', 'App\Company\Controller\ReportApiKeyController::generate'],
        'settings_report_api_key_revoke' => ['owner', 'App\Company\Controller\ReportApiKeyController::revoke'],
        // Тонкие экшены: владельца сверяет Action.
        'company_member_disable' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::disableMember'],
        'company_member_enable' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::enableMember'],
        'company_member_disable_legacy' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::legacyDisable'],
        'company_member_enable_legacy' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::legacyEnable'],
        'company_users_invite_legacy' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::legacyInvite'],
        'company_invite_revoke_legacy' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::legacyRevoke'],
        'company_member_access_role_legacy' => ['delegated-owner', 'App\Company\Controller\CompanyMemberController::legacySetAccessRole'],
        // Компании: сверка владельца инлайном, работает и без активной компании.
        'company_new' => ['inline-owner', 'App\Company\Controller\CompanyController::new'],
        'company_edit' => ['inline-owner', 'App\Company\Controller\CompanyController::edit'],
        'company_delete' => ['inline-owner', 'App\Company\Controller\CompanyController::delete'],
        'company_set_active' => ['inline-owner', 'App\Company\Controller\CompanyController::setActive'],
        // Личные настройки пользователя.
        'app_profile_password' => ['authenticated-self', 'App\Company\Controller\ProfileController::changePassword'],
        'app_ui_mode_switch' => ['authenticated-self', 'App\Shared\Controller\UiModeController::__invoke'],
        // Админка: отдельный firewall и access_control.
        'admin_auth_login' => ['firewall', 'App\Admin\Controller\Security\AdminAuthController::login'],
        'admin_user_create_account' => ['firewall', 'App\Admin\Controller\CreateAccountController::__invoke'],
        'admin_user_update_roles' => ['firewall', 'App\Admin\Controller\UserController::updateRoles'],
        'admin_telegram_bot_new' => ['firewall', 'App\Telegram\Controller\Admin\TelegramBotController::new'],
        'admin_telegram_bot_edit' => ['firewall', 'App\Telegram\Controller\Admin\TelegramBotController::edit'],
        'admin_telegram_bot_toggle' => ['firewall', 'App\Telegram\Controller\Admin\TelegramBotController::toggle'],
        'admin_telegram_bot_webhook_set' => ['firewall', 'App\Telegram\Controller\Admin\TelegramBotController::webhookSet'],
        'admin_ingestion_external_categories_discover' => ['firewall', 'App\Admin\Controller\IngestionExternalCategoriesController::discover'],
        'admin_ingestion_external_categories_refresh_ozon_metadata' => ['firewall', 'App\Admin\Controller\IngestionExternalCategoriesController::refreshOzonMetadata'],
        'admin_ingestion_external_categories_seed_defaults' => ['firewall', 'App\Admin\Controller\IngestionExternalCategoriesController::seedDefaults'],
        'admin_ingestion_external_categories_update_mapping' => ['firewall', 'App\Admin\Controller\IngestionExternalCategoriesController::updateMapping'],
        'admin_marketplace_mapping_error_resolve' => ['firewall', 'App\Marketplace\Controller\Admin\MappingErrorResolveController::__invoke'],
        'marketplace_ads_admin_mark_load_job_failed' => ['firewall', 'App\MarketplaceAds\Controller\Api\Admin\MarkAdLoadJobFailedController::__invoke'],
    ];

    /** Минимум мутирующих маршрутов на модуль — против «тихого» зануления обхода. */
    private const MIN_MUTATING_PER_MODULE = [
        'finance' => 40,
        'marketplace' => 50,
        'deals' => 5,
        'catalog' => 3,
        // В admin модульный write-гейт остался только у telegram-интеграции: остальные мутации
        // группы закрыты строже и перечислены в ROUTE_POLICY.
        'admin' => 1,
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Гейты строже модульного. Сравниваются точно: `ROLE_ADMIN` не должен совпадать
     * с гипотетическим `ROLE_ADMIN_SOMETHING`.
     *
     * `ROLE_COMPANY_OWNER` сюда НЕ входит: это глобальная роль из role_hierarchy, её ставит
     * CompanyOwnerAccountCreator при регистрации. Она означает «пользователь зарегистрирован
     * как владелец компании», а не «владелец активной компании», поэтому владелец компании A,
     * будучи read-only участником компании B, прошёл бы такой гейт и записал бы в компанию B.
     * Заменой tenant-scoped write-гейту она быть не может — только дополнением.
     */
    private const STRICTER_ATTRIBUTES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function testEveryMutatingRouteInCoveredModulesIsGatedByItsOwnModule(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $map = new ModuleAccessMap();

        $problems = [];
        $perModule = array_fill_keys(self::COVERED_MODULES, 0);
        $mutatingByClass = [];
        $readByClass = [];
        $usedRoutePolicies = [];

        foreach ($router->getRouteCollection() as $routeName => $route) {
            $controller = (string) ($route->getDefaults()['_controller'] ?? '');
            if ('' === $controller) {
                continue;
            }

            [$className, $methodName] = $this->splitController($controller);

            if (!str_starts_with($controller, 'App\\') || !class_exists($className)) {
                // Не наш контроллер или неразрешимый callable. Read-маршруты фреймворка
                // игнорируем, но мутирующий неразрешимый маршрут прятать нельзя.
                if ($this->isMutatingRoute($route) && str_starts_with($controller, 'App\\')) {
                    $problems[] = sprintf('%s (%s) — контроллер не разрешается в класс', $routeName, $controller);
                }

                continue;
            }

            $isMutating = $this->isMutatingRoute($route);
            $exempt = $map->isExempt($className);
            $module = $map->resolve($className);

            // Read-маршруты интересны только как контекст для проверки class-level write-гейта.
            if (!$isMutating) {
                if (!$exempt && null !== $module && \in_array($module->value, self::COVERED_MODULES, true)) {
                    $readByClass[$className][] = $routeName;
                }

                continue;
            }

            // Явное осознанное исключение: логин, регистрация, приём инвайта, вебхуки.
            if ($this->hasPublicAccess($className, $methodName)) {
                continue;
            }

            if (isset(self::ROUTE_POLICY[$routeName])) {
                [$policy, $expectedController] = self::ROUTE_POLICY[$routeName];
                $actualController = $className.'::'.$methodName;
                if ($expectedController !== $actualController) {
                    $problems[] = sprintf(
                        '%s — политика %s выписана на %s, а маршрут ведёт на %s',
                        $routeName,
                        $policy,
                        $expectedController,
                        $actualController,
                    );
                }

                $usedRoutePolicies[$routeName] = $policy;

                continue;
            }

            if ($exempt) {
                $problems[] = sprintf('%s (%s::%s) — мутирующий маршрут в exempt-зоне без записи в ROUTE_POLICY', $routeName, $className, $methodName);

                continue;
            }

            if (null === $module) {
                // Заявленное правило: неклассифицированный контроллер — падение, а не пропуск.
                // Иначе перенос контроллера в нераспознанный namespace снимает с него надзор.
                $problems[] = sprintf('%s (%s::%s) — не классифицирован в ModuleAccessMap', $routeName, $className, $methodName);

                continue;
            }

            if (!\in_array($module->value, self::COVERED_MODULES, true)) {
                continue;
            }

            $mutatingByClass[$className][] = $routeName;
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
        $stale = array_diff(array_keys(self::ROUTE_POLICY), array_keys($usedRoutePolicies));
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

        // Смешанные GET+POST экшены гейтятся в теле: атрибут на метод закрыл бы и чтение.
        // Признаётся только точная форма с константой своего модуля, а тело читается
        // по PHP-токенам без комментариев и строк — закомментированный вызов не считается.
        return str_contains(
            $this->executableBody($method),
            'denyAccessUnlessGranted('.$this->writeConstant($module).')',
        );
    }

    /** Тело метода без комментариев и строковых литералов. */
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
}
