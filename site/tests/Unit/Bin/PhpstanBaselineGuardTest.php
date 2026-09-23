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
    protected function tearDown(): void
    {
        $root = sys_get_temp_dir().'/baseline-guard-renames-'.getmypid();
        if (is_dir($root)) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

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

    /**
     * Переименования: база переписывается по карте `git diff -M`, текущий
     * baseline — нет. Поэтому перенос сам по себе проходит, а новая ошибка или
     * рост count в перенесённом файле по-прежнему роняют проверку.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function renameScenarios(): iterable
    {
        yield 'только переименование, записи те же' => ['head-renamed.neon', 'map.tsv', 0];
        yield 'переименование и новая ошибка в перенесённом файле' => ['head-renamed-new.neon', 'map.tsv', 1];
        yield 'переименование и рост count' => ['head-renamed-grew.neon', 'map.tsv', 1];
        yield 'пустая карта — как без неё' => ['head-renamed.neon', 'empty-map.tsv', 1];
    }

    #[DataProvider('renameScenarios')]
    public function testGuardAppliesRenameMap(string $head, string $map, int $expected): void
    {
        $data = __DIR__.'/data/renames/';

        exec($this->renameCommand($data.'base.neon', $data.$head, $data.$map).' > /dev/null 2>&1', $output, $exitCode);

        self::assertSame($expected, $exitCode);
    }

    /**
     * Переписывание склеило бы две разные записи базы в один ключ, и guard
     * сложил бы их count: рост одной прошёл бы как перенос. Такой случай —
     * отказ, а не зелёный.
     */
    public function testGuardFailsClosedWhenRenameCollapsesDistinctEntries(): void
    {
        $data = __DIR__.'/data/renames/';

        exec($this->renameCommand($data.'base-collision.neon', $data.'head-renamed.neon', $data.'map.tsv').' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('склеило разные записи', implode("\n", $output));
    }

    /**
     * Та же склейка через второстепенный класс: перенесённый файл объявляет
     * FooHelper, а база уже упоминала и старый, и новый FooHelper.
     */
    public function testGuardFailsClosedWhenSecondaryClassCollapsesEntries(): void
    {
        $data = __DIR__.'/data/renames/';

        exec($this->renameCommand($data.'base-collision-secondary.neon', $data.'head-renamed.neon', $data.'map.tsv').' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('склеило разные записи', implode("\n", $output));
    }

    public function testGuardReportsHowManyRenamesWereApplied(): void
    {
        $data = __DIR__.'/data/renames/';

        exec($this->renameCommand($data.'base.neon', $data.'head-renamed.neon', $data.'map.tsv').' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Учтено переименований: 2', implode("\n", $output));
    }

    private function renameCommand(string $base, string $head, string $map): string
    {
        return sprintf(
            '%s %s %s %s %s',
            escapeshellarg(\dirname(__DIR__, 3).'/bin/phpstan-baseline-guard.sh'),
            escapeshellarg($base),
            escapeshellarg($head),
            escapeshellarg($map),
            escapeshellarg($this->renameRoot()),
        );
    }

    /**
     * Новые версии перенесённых файлов: helper читает из них второстепенные
     * классы (FooHelper). Создаются во временном каталоге, а не лежат в
     * tests/: иначе их подхватили бы PHPUnit, PHPStan и cs-fixer.
     */
    private function renameRoot(): string
    {
        $root = sys_get_temp_dir().'/baseline-guard-renames-'.getmypid();
        $files = [
            'src/New/Foo.php' => "<?php\n\nnamespace App\\New;\n\nfinal class Foo\n{\n}\n",
            'tests/Unit/New/FooTest.php' => "<?php\n\nnamespace App\\Tests\\Unit\\New;\n\nfinal class FooTest\n{\n}\n\nfinal class FooHelper\n{\n}\n",
        ];
        foreach ($files as $path => $content) {
            if (!is_dir(\dirname($root.'/'.$path))) {
                mkdir(\dirname($root.'/'.$path), 0o777, true);
            }
            file_put_contents($root.'/'.$path, $content);
        }

        return $root;
    }

    /**
     * Больше 20 новых записей: отчёт режется на первых двадцати. Раньше это
     * делал `printf | head`, и при pipefail скрипт выходил с кодом 141 (SIGPIPE)
     * и «Broken pipe» в логе CI вместо штатного отказа.
     */
    public function testManyNewEntriesFailWithRegularExitCode(): void
    {
        $data = __DIR__.'/data/';
        $head = tempnam(sys_get_temp_dir(), 'baseline-head-');
        self::assertIsString($head);
        $entries = '';
        for ($i = 0; $i < 30; ++$i) {
            $entries .= "\t\t-\n\t\t\tmessage: '#^Новая ошибка {$i}$#'\n\t\t\tidentifier: test.rule\n\t\t\tcount: 1\n\t\t\tpath: src/New{$i}.php\n\n";
        }
        file_put_contents($head, "parameters:\n\tignoreErrors:\n".$entries);

        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(\dirname(__DIR__, 3).'/bin/phpstan-baseline-guard.sh'),
            escapeshellarg($data.'base.neon'),
            escapeshellarg($head),
        ), $output, $exitCode);
        unlink($head);

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('Broken pipe', implode("\n", $output));
        self::assertStringContainsString('и ещё 10', implode("\n", $output));
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
