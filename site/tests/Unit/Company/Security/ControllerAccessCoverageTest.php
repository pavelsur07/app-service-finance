<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\ModuleAccessMap;
use App\Company\Security\PublicAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Каждый HTTP-контроллер в src/ обязан быть классифицирован.
 *
 * Скан: все *.php в каталогах src/.../Controller/ плюс *Controller.php в остальном src.
 * Класс считается контроллером при атрибуте Route на классе или методах
 * (IS_INSTANCEOF обязателен: ~76 контроллеров импортируют Routing\Annotation\Route — class_alias,
 * литеральное сравнение имён их не находит).
 *
 * Логика согласована с ModuleAccessSubscriber (method-level #[PublicAccess] освобождает
 * только свой метод). Класс классифицирован, если:
 * - class-level #[PublicAccess], ИЛИ
 * - exempt в ModuleAccessMap, ИЛИ
 * - привязан к модулю (resolve), ИЛИ
 * - у класса есть routed-методы и ВСЕ они помечены method-level #[PublicAccess].
 *
 * Без ядра: файлы + рефлексия.
 */
final class ControllerAccessCoverageTest extends TestCase
{
    public function testEveryControllerIsClassified(): void
    {
        $srcDir = \dirname(__DIR__, 4).'/src';
        $map = new ModuleAccessMap();

        $finder = (new Finder())
            ->files()
            ->in($srcDir)
            ->name('*.php');

        $unclassified = [];
        $count = 0;

        foreach ($finder as $file) {
            $path = $file->getPathname();
            $isController = str_ends_with($file->getFilename(), 'Controller.php')
                || str_contains($path, '/Controller/');
            if (!$isController) {
                continue;
            }

            $className = $this->classNameFromPath($srcDir, $path);
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            if (!$this->hasRouteAttribute($reflection)) {
                continue;
            }

            ++$count;

            if ($this->isClassified($reflection, $className, $map)) {
                continue;
            }

            $unclassified[] = $className;
        }

        // Факт на 2026-08-08: 200 routed-контроллеров. Нижняя граница с запасом ~10%
        // против «тихого» зануления скана (например, при смене неймспейсов атрибутов).
        self::assertGreaterThan(180, $count, 'Контроллеры не найдены — проверьте скан src/.');
        self::assertSame(
            [],
            $unclassified,
            sprintf(
                "Контроллеры без модульной классификации:\n%s\nДобавьте их в ModuleAccessMap или пометьте #[PublicAccess].",
                implode("\n", $unclassified),
            ),
        );
    }

    private function isClassified(\ReflectionClass $reflection, string $className, ModuleAccessMap $map): bool
    {
        if ([] !== $reflection->getAttributes(PublicAccess::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        if ($map->isExempt($className)) {
            return true;
        }

        if (null !== $map->resolve($className)) {
            return true;
        }

        // Method-level #[PublicAccess] освобождает только свой метод:
        // класс классифицирован, только если помечены ВСЕ его routed-методы.
        $routedMethods = $this->routedMethods($reflection);
        if ([] === $routedMethods) {
            return false;
        }

        foreach ($routedMethods as $method) {
            if ([] === $method->getAttributes(PublicAccess::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<\ReflectionMethod>
     */
    private function routedMethods(\ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            if ([] !== $method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    private function classNameFromPath(string $srcDir, string $path): string
    {
        $relative = substr($path, \strlen($srcDir) + 1, -4);

        return 'App\\'.str_replace('/', '\\', $relative);
    }

    private function hasRouteAttribute(\ReflectionClass $reflection): bool
    {
        if ([] !== $reflection->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        return [] !== $this->routedMethods($reflection);
    }
}
