<?php

declare(strict_types=1);

/**
 * `php -l` по всем исходникам пакета.
 *
 * Написано на PHP, а не как шелл-однострочник с `find`, потому что рабочая
 * машина разработки — Windows без coreutils: `composer lint` с `find` там
 * падает всегда. Здесь же единственная зависимость — сам PHP, который для
 * запуска скрипта и так нужен. Заодно линтуются `examples/`, которые
 * шелл-вариант не покрывал.
 *
 * Выход: 0 — всё разобралось, 1 — есть синтаксические ошибки.
 *
 * ВНИМАНИЕ: `php -l` видит только ошибки разбора. Ошибки связывания
 * (например, переобъявление унаследованного свойства как readonly) проявятся
 * лишь при реальной загрузке класса — их ловит phpunit, а не этот линтер,
 * поэтому `composer test` обязателен перед релизом.
 */

$root = dirname(__DIR__);
$dirs = ['src', 'tests', 'examples', 'tools'];

$files = [];
foreach ($dirs as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

$failed = 0;
foreach ($files as $file) {
    $output = [];
    $status = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1',
        $output,
        $status,
    );
    if ($status !== 0) {
        $failed++;
        echo implode(PHP_EOL, $output), PHP_EOL;
    }
}

printf('%d file(s) checked, %d with syntax errors%s', count($files), $failed, PHP_EOL);

exit($failed === 0 ? 0 : 1);
