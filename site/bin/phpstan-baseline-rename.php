<?php

declare(strict_types=1);

/*
 * Переписывает baseline PHPStan базы сравнения под переименования файлов.
 *
 * Зачем: phpstan-baseline-guard.sh сравнивает записи по ключу
 * (path, identifier, message). Переименованный файл уносит с собой и путь, и
 * полное имя класса в message — без нормализации guard считает все его записи
 * новыми (так было на переносах WB/Ozon, #2506 и #2507: 248 «новых» записей при
 * неизменных 2410), и остаётся только метка [baseline-grow], которая выключает
 * проверку целиком.
 *
 * Переписывается только база. Запись, которой в базе не было, после
 * переписывания всё равно не найдёт пары и останется NEW; рост count — GREW.
 *
 * Вход:  <base.neon> <rename-map> [<root>]
 *   rename-map — вывод `git diff -M --name-status --diff-filter=R`: строки
 *                `R<score>\t<old>\t<new>`; префикс `site/` срезается, учитываются
 *                только src/… и tests/… .php;
 *   root       — каталог site/ (по умолчанию родитель bin/): из него читаются
 *                новые версии файлов, чтобы найти объявленные в них классы.
 * Выход: переписанный baseline в stdout, статистика в stderr.
 */

// Ошибки — исключениями: CLI завершится с ненулевым кодом и текстом в stderr,
// guard это ловит и не сравнивает с непереписанной базой.
if ($argc < 3) {
    throw new InvalidArgumentException('Использование: phpstan-baseline-rename.php <base.neon> <rename-map> [<root>]');
}

[, $baseFile, $mapFile] = $argv;
$root = rtrim($argv[3] ?? dirname(__DIR__), '/');

$base = file_get_contents($baseFile);
$map = file_get_contents($mapFile);
if (false === $base || false === $map) {
    throw new RuntimeException('Не удалось прочитать входные файлы.');
}

/** Полное имя класса по PSR-4 проекта: src/ → App\, tests/ → App\Tests\. */
function fqcnOf(string $path): ?string
{
    if (!str_ends_with($path, '.php')) {
        return null;
    }
    $noExt = substr($path, 0, -4);
    if (str_starts_with($noExt, 'src/')) {
        return 'App\\'.str_replace('/', '\\', substr($noExt, 4));
    }
    if (str_starts_with($noExt, 'tests/')) {
        return 'App\\Tests\\'.str_replace('/', '\\', substr($noExt, 6));
    }

    return null;
}

function namespaceOf(string $fqcn): string
{
    $pos = strrpos($fqcn, '\\');

    return false === $pos ? '' : substr($fqcn, 0, $pos);
}

$paths = [];   // old path => new path
$classes = []; // old FQCN => new FQCN
foreach (preg_split('/\R/', $map) ?: [] as $line) {
    $cols = explode("\t", trim($line));
    if (3 !== count($cols) || !str_starts_with($cols[0], 'R')) {
        continue;
    }
    $old = preg_replace('#^site/#', '', $cols[1]) ?? $cols[1];
    $new = preg_replace('#^site/#', '', $cols[2]) ?? $cols[2];
    $oldFq = fqcnOf($old);
    $newFq = fqcnOf($new);
    if (null === $oldFq || null === $newFq) {
        continue;
    }
    $paths[$old] = $new;
    $classes[$oldFq] = $newFq;

    // Второстепенные классы (заглушки внутри тестов и т. п.) едут вместе с файлом.
    $source = @file_get_contents($root.'/'.$new);
    if (false !== $source && preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $m)) {
        foreach ($m[1] as $name) {
            $classes[namespaceOf($oldFq).'\\'.$name] = namespaceOf($newFq).'\\'.$name;
        }
    }
}

// Длинные имена раньше коротких: App\X\FooBar не должен переписаться как App\X\Foo.
uksort($classes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
uksort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

$patterns = [];
$replacements = [];
foreach ($classes as $old => $new) {
    // Формы записи в message: `\` (редко), `\\` (regex-экранирование neon) и
    // `\\\\` (строковый литерал внутри regex).
    foreach ([1, 2, 4] as $k) {
        $sep = str_repeat('\\', $k);
        $patterns[] = '/(?<![A-Za-z0-9_])'.preg_quote(str_replace('\\', $sep, $old), '/').'(?![A-Za-z0-9_])/';
        $replacements[] = addcslashes(str_replace('\\', $sep, $new), '\\$');
    }
}
foreach ($paths as $old => $new) {
    $patterns[] = '/'.preg_quote($old, '/').'(?![A-Za-z0-9_])/';
    $replacements[] = addcslashes($new, '\\$');
    // Пути анонимных классов в message экранированы: `…Test\.php:NN`.
    $oldEsc = substr($old, 0, -4).'\\.php';
    $newEsc = substr($new, 0, -4).'\\.php';
    $patterns[] = '/'.preg_quote($oldEsc, '/').'(?![A-Za-z0-9_])/';
    $replacements[] = addcslashes($newEsc, '\\$');
}

$rewritten = 0;
$out = [];
foreach (preg_split('/(?<=\n)/', $base) ?: [] as $line) {
    if ([] !== $patterns && preg_match('/^\s*(message|path):/', $line)) {
        $new = preg_replace($patterns, $replacements, $line) ?? $line;
        if ($new !== $line) {
            ++$rewritten;
            $line = $new;
        }
    }
    $out[] = $line;
}

fwrite(STDOUT, implode('', $out));
fwrite(STDERR, sprintf("Учтено переименований: %d, переписано строк базы: %d.\n", count($paths), $rewritten));
