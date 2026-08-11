<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Security\AccessLevel;
use App\Company\Security\ModuleAccessMap;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\RouterInterface;

/**
 * Поведенческое доказательство для смешанных `GET+POST` маршрутов: участнику с правом
 * `<module>:read` чтение доступно, а запись отклоняется.
 *
 * Статический инвариант (ModuleWriteGateCoverageTest) может только увидеть текст вызова
 * `denyAccessUnlessGranted()`, но не доказать, что он исполняется именно на POST и до мутации.
 * Здесь это проверяется запросами: 403 на POST и отсутствие 403 на GET по каждому такому маршруту.
 *
 * Параметры пути подставляются случайным UUID, поэтому на POST допустимы два исхода:
 * 403 (сработал гейт) и 404 (сущности нет или эндпоинт закрыт биллинговым флагом —
 * например FundController::new вызывает assertFeatureEnabled() до гейта). Оба означают,
 * что до записи дело не дошло. Любой другой код — 200, 302, 422, 5xx — означает, что путь
 * записи выполнился, и тест падает.
 */
final class ModuleMixedRouteGateTest extends WebTestCaseBase
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Модули, для которых расставлены write-гейты и есть смешанные маршруты. */
    private const MODULES = ['finance', 'deals', 'catalog', 'marketplace'];

    /**
     * Беспараметрические маршруты, где 404 приходит раньше гейта по биллинговому флагу
     * (`assertFeatureEnabled()` в FundController). Перечислены поимённо, чтобы послабление
     * не расползалось: для всех остальных беспараметрических маршрутов требуется ровно 403.
     *
     * @var list<string>
     */
    private const FEATURE_GATED_ROUTES = ['finance_funds_new'];

    public function testMixedRoutesAllowReadAndDenyWriteForReadOnlyRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $router = self::getContainer()->get(RouterInterface::class);
        $map = new ModuleAccessMap();

        $byModule = [];
        foreach ($router->getRouteCollection() as $routeName => $route) {
            $controller = (string) ($route->getDefaults()['_controller'] ?? '');
            if (!str_starts_with($controller, 'App\\')) {
                continue;
            }

            // Пустой список методов = маршрут принимает всё, значит он тоже смешанный.
            $methods = $route->getMethods();
            $acceptsRead = [] === $methods || \in_array('GET', $methods, true);
            $acceptsWrite = [] === $methods || [] !== array_intersect($methods, self::MUTATING_METHODS);
            if (!$acceptsRead || !$acceptsWrite) {
                continue;
            }

            $className = explode('::', $controller)[0];
            if ($map->isExempt($className)) {
                continue;
            }

            $module = $map->resolve($className);
            if (null === $module || !\in_array($module->value, self::MODULES, true)) {
                continue;
            }

            $byModule[$module->value][$routeName] = $route->getPath();
        }

        self::assertNotSame([], $byModule, 'Смешанные маршруты не найдены — проверьте обход RouteCollection.');

        $problems = [];
        $checked = 0;
        $moduleIndex = 0;

        foreach (self::MODULES as $moduleValue) {
            if (!isset($byModule[$moduleValue])) {
                continue;
            }

            [$company, $memberUser] = $this->seedReadOnlyMember($moduleValue, $moduleIndex);
            ++$moduleIndex;
            $client->loginUser($memberUser);
            $this->setClientSessionValue($client, 'active_company_id', $company->getId());

            foreach ($byModule[$moduleValue] as $routeName => $path) {
                ++$checked;
                $url = $this->fillPlaceholders($path);

                $client->request('GET', $url);
                if (403 === $client->getResponse()->getStatusCode()) {
                    $problems[] = sprintf('%s — GET отдал 403 участнику с %s:read (гейт отрезал чтение)', $routeName, $moduleValue);
                }

                $client->request('POST', $url);
                $status = $client->getResponse()->getStatusCode();

                // Для маршрута без параметров 404 нечем оправдать: сущность не искалась.
                // Значит гейт обязан отдать ровно 403 — кроме явно feature-gated случаев.
                $hasPlaceholders = str_contains($path, '{');
                $allowed = ($hasPlaceholders || \in_array($routeName, self::FEATURE_GATED_ROUTES, true))
                    ? [403, 404]
                    : [403];

                if (!\in_array($status, $allowed, true)) {
                    $problems[] = sprintf(
                        '%s — POST отдал %d участнику без %s:write; ожидалось %s',
                        $routeName,
                        $status,
                        $moduleValue,
                        implode(' или ', $allowed),
                    );
                }
            }
        }

        // Факт на 2026-08-11: 42 смешанных маршрута. Нижняя граница с запасом.
        self::assertGreaterThan(30, $checked, 'Смешанных маршрутов найдено меньше ожидаемого.');
        self::assertSame([], $problems, "Смешанные маршруты с неверным гейтом:\n".implode("\n", $problems));
    }

    private function fillPlaceholders(string $path): string
    {
        return (string) preg_replace_callback(
            '/\{[^}]+\}/',
            static fn (): string => Uuid::uuid4()->toString(),
            $path,
        );
    }

    /**
     * @return array{0: \App\Company\Entity\Company, 1: \App\Company\Entity\User}
     */
    private function seedReadOnlyMember(string $moduleValue, int $index): array
    {
        // Индексы билдеров дают детерминированные UUID, поэтому у каждого модуля они свои:
        // иначе четыре набора сущностей столкнутся в identity map.
        $owner = UserBuilder::aUser()
            ->withIndex(100 + $index)
            ->withEmail(sprintf('mixed-owner-%s@example.test', $moduleValue))
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex(100 + $index)
            ->withOwner($owner)
            ->withName('Mixed Route Company '.$moduleValue)
            ->build();

        $role = new CompanyRole(
            Uuid::uuid4()->toString(),
            'Только чтение '.$moduleValue,
            [$moduleValue => AccessLevel::READ->value],
            $company,
        );

        $memberUser = UserBuilder::aUser()
            ->withIndex(200 + $index)
            ->withEmail(sprintf('mixed-member-%s@example.test', $moduleValue))
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withStatus(CompanyMember::STATUS_ACTIVE)
            ->withAccessRole($role)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($role);
        $em->persist($memberUser);
        $em->persist($member);
        $em->flush();

        return [$company, $memberUser];
    }
}
