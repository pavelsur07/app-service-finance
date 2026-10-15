<?php

declare(strict_types=1);

namespace App\Tests\Support\Mock;

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Мок final-класса (их разрешает dg/bypass-finals, см. `tests/bootstrap.php`) без
 * роста baseline PHPStan.
 *
 * Прямой `createMock(Final::class)` PHPStan типизирует как `Final&MockObject` —
 * у final-класса такого подтипа нет, и вызов даёт «unresolvable type». Здесь класс
 * приходит строкой, мок настраивается как `MockObject`, а к типу класса его
 * приводит `asInstanceOf()` с параметром `object`.
 */
trait MocksFinalClasses
{
    /**
     * @param class-string $class
     */
    private function createFinalMock(string $class): MockObject
    {
        return $this->createMock($class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function asInstanceOf(object $mock, string $class): object
    {
        self::assertInstanceOf($class, $mock);

        return $mock;
    }
}
