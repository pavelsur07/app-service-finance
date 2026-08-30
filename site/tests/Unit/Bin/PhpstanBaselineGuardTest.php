<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Поведение `bin/phpstan-baseline-guard.sh` как коммитируемый тест, а не как
 * ручной прогон. Сценарии подмены записи и смешанной формы NEON важны отдельно:
 * первые редакции скрипта проходили их молча, то есть делали вид, что роста
 * baseline нет.
 */
final class PhpstanBaselineGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function scenarios(): iterable
    {
        yield 'без изменений' => ['base.neon', 'same.neon', 0];
        yield 'честное сокращение' => ['base.neon', 'shrunk.neon', 0];
        yield 'пустая база — сравнивать не с чем' => ['empty.neon', 'base.neon', 0];
        yield 'подмена записи при равных агрегатах' => ['base.neon', 'swap.neon', 1];
        yield 'рост count с компенсацией другой записи' => ['base.neon', 'compensated.neon', 1];
        yield 'flow-форма NEON целиком' => ['base.neon', 'flow.neon', 1];
        yield 'смешанная форма: каноническая запись прикрывает flow' => ['base.neon', 'mixed.neon', 1];
        yield 'запись без count — для PHPStan это снятие ограничения' => ['base.neon', 'missing-count.neon', 1];
        yield 'flow-запись с закавыченными ключами' => ['base.neon', 'quoted-flow.neon', 1];
        yield 'count: 0 — не положительное целое' => ['base.neon', 'zero-count.neon', 1];
        yield 'count: abc — не число' => ['base.neon', 'bad-count.neon', 1];
    }

    #[DataProvider('scenarios')]
    public function testGuardExitCode(string $base, string $head, int $expected): void
    {
        $script = \dirname(__DIR__, 3).'/bin/phpstan-baseline-guard.sh';
        $data = __DIR__.'/data/';

        $command = sprintf(
            '%s %s %s > /dev/null 2>&1',
            escapeshellarg($script),
            escapeshellarg($data.$base),
            escapeshellarg($data.$head),
        );

        exec($command, $output, $exitCode);

        self::assertSame($expected, $exitCode);
    }

    public function testGuardFailsWhenBaselineCannotBeParsedEntirely(): void
    {
        $script = \dirname(__DIR__, 3).'/bin/phpstan-baseline-guard.sh';
        $data = __DIR__.'/data/';

        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($data.'base.neon'),
            escapeshellarg($data.'mixed.neon'),
        );

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('не канонический', implode("\n", $output));
    }
}
