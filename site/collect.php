<?php
// collect.php
// docker-compose run --rm site-php-cli php collect.php
$excludeDirs = ['vendor', 'var', 'node_modules', '.git', 'public/bundles', 'tests', 'migrations'];
$extensions = ['php', 'yaml', 'twig', 'json', 'env'];
$outputFile = 'symfony_context.txt';

$rootPath = __DIR__;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS)
);
$handle = fopen($outputFile, 'w');

if (!$handle) {
    die("Не удалось создать файл $outputFile");
}

foreach ($iterator as $file) {
    $path = $file->getPathname();
    // Исправлено: убраны лишние пробелы в названии переменной
    $relativePath = str_replace($rootPath . DIRECTORY_SEPARATOR, '', $path);

    $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
    if (array_intersect($pathParts, $excludeDirs)) {
        continue;
    }

    if (!in_array($file->getExtension(), $extensions)) {
        continue;
    }

    if ($file->getFilename() === 'collect.php' || $file->getFilename() === $outputFile) {
        continue;
    }

    fwrite($handle, "\n\n--- FILE: $relativePath ---\n\n");
    fwrite($handle, file_get_contents($path));
}

fclose($handle);
echo "Готово! Файл $outputFile создан.\n";
