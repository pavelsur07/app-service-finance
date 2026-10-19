<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

/**
 * Разбор аргументов инструмента: отсутствующее поле — это «не менять», а не ошибка.
 * Неизвестное значение enum отклоняется с перечислением допустимых, чтобы модель исправилась сама.
 */
trait EnumArgument
{
    /**
     * @param array<string, mixed> $arguments
     */
    private function stringArg(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $arguments
     * @param class-string<T>      $enum
     *
     * @return T|null
     */
    private function enumArg(array $arguments, string $key, string $enum): ?\BackedEnum
    {
        $value = $this->stringArg($arguments, $key);
        if (null === $value) {
            return null;
        }

        $case = $enum::tryFrom($value);
        if (null === $case) {
            throw new \InvalidArgumentException(sprintf('Недопустимое значение "%s" для %s. Допустимые: %s.', $value, $key, implode(', ', array_column($enum::cases(), 'value'))));
        }

        return $case;
    }
}
