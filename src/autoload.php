<?php
declare(strict_types=1);

// Parliament Chess — MIT License. A PSR-4 loader so the project needs no Composer install.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Parliament\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\Parliament\Engine\Position::init();

/** Project root. */
function pc_root(): string
{
    return dirname(__DIR__);
}

/** Where games, recordings and the book live: data/, or $PC_DATA_DIR (the tests' own folder). */
function pc_data(): string
{
    $d = getenv('PC_DATA_DIR') ?: pc_root() . '/data';
    return rtrim($d, '/');
}
