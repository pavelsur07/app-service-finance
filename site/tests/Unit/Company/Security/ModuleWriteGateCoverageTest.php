<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\ModuleAccessMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Каждый мутирующий экшен в модулях с расставленными write-гейтами обязан быть закрыт.
 *
 * Инвариант держит расстановку Stage 3–4 от тихой эрозии: новый POST-экшен в этих
 * неймспейсах без гейта роняет тест, а не уезжает в прод открытым.
 *
 * Признаётся закрытым, если у метода есть `#[IsGranted]` с атрибутом `module.*.write`,
 * либо в теле есть `denyAccessUnlessGranted(ModuleAccess::` (смешанные GET+POST экшены),
 * либо экшен/класс закрыт более строгим гейтом — owner-only или ROLE_ADMIN.
 *
 * Без ядра: файлы + рефлексия, как в ControllerAccessCoverageTest.
 */
final class ModuleWriteGateCoverageTest extends TestCase
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

    /** Свои гейты, модульные write-гейты к ним не применяются. */
    private const EXCLUDED_PREFIXES = [
        'App\\Marketplace\\Controller\\Admin\\',
        'App\\MarketplaceAds\\Controller\\Api\\Admin\\',
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Более строгие гейты: owner-only и админские. Ослаблять их модульными нельзя. */
    private const STRICTER_GATES = [
        'ROLE_COMPANY_OWNER',
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
        'assertOwner',
        'isOwner',
    ];

    public function testEveryMutatingActionInCoveredModulesIsGated(): void
    {
        $srcDir = \dirname(__DIR__, 4).'/src';
        $map = new ModuleAccessMap();

        $finder = (new Finder())->files()->in($srcDir)->name('*.php');

        $ungated = [];
        $checked = 0;

        foreach ($finder as $file) {
            $path = $file->getPathname();
            if (!str_contains($path, '/Controller/') && !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }

            $className = 'App\\'.str_replace('/', '\\', substr($path, \strlen($srcDir) + 1, -4));
            if (!class_exists($className)) {
                continue;
            }
            if (!$this->isCovered($className) || null === $map->resolve($className)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $reflection = new \ReflectionClass($className);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
                if (!$this->isMutatingRoute($method)) {
                    continue;
                }

                ++$checked;

                if (!$this->isGated($method, $source)) {
                    $ungated[] = $className.'::'.$method->getName();
                }
            }
        }

        // Факт на 2026-08-11: 130 мутирующих экшенов в покрытых модулях. Нижняя граница
        // с запасом против «тихого» зануления скана.
        self::assertGreaterThan(90, $checked, 'Мутирующие экшены не найдены — проверьте скан src/.');
        self::assertSame(
            [],
            $ungated,
            sprintf(
                "Мутирующие экшены без write-гейта:\n%s\n"
                .'Добавьте #[IsGranted(ModuleAccess::<MODULE>_WRITE)] либо denyAccessUnlessGranted() для смешанных GET+POST.',
                implode("\n", $ungated),
            ),
        );
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

    private function isMutatingRoute(\ReflectionMethod $method): bool
    {
        foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $methods = $attribute->getArguments()['methods'] ?? null;
            if (null === $methods) {
                // Роут без явного methods принимает всё, но такие экшены в этих модулях —
                // read-страницы (проверено при инвентаризации Stage 4). Не требуем гейта.
                continue;
            }

            $methods = array_map('strtoupper', (array) $methods);
            if ([] !== array_intersect($methods, self::MUTATING_METHODS)) {
                return true;
            }
        }

        return false;
    }

    private function isGated(\ReflectionMethod $method, string $source): bool
    {
        foreach ($method->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $subject = (string) ($attribute->getArguments()[0] ?? $attribute->getArguments()['attribute'] ?? '');
            if (str_ends_with($subject, '.write')) {
                return true;
            }
            if ($this->isStricterGate($subject)) {
                return true;
            }
        }

        // Класс целиком под более строгим гейтом.
        foreach ($method->getDeclaringClass()->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            if ($this->isStricterGate((string) ($attribute->getArguments()[0] ?? ''))) {
                return true;
            }
        }

        return $this->bodyHasGate($method, $source);
    }

    private function isStricterGate(string $needle): bool
    {
        foreach (self::STRICTER_GATES as $gate) {
            if (str_contains($needle, $gate)) {
                return true;
            }
        }

        return false;
    }

    private function bodyHasGate(\ReflectionMethod $method, string $source): bool
    {
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (false === $start || false === $end) {
            return false;
        }

        $body = implode("\n", \array_slice(explode("\n", $source), $start - 1, $end - $start + 1));

        if (str_contains($body, 'denyAccessUnlessGranted(ModuleAccess::')) {
            return true;
        }

        foreach (self::STRICTER_GATES as $gate) {
            if (str_contains($body, $gate)) {
                return true;
            }
        }

        return false;
    }
}
