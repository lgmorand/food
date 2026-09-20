<?php

declare(strict_types=1);

/**
 * Point d'entrée unique de l'application (autoload + configuration).
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Food\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('Food\\')));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

if (!defined('FOOD_ROOT')) {
    define('FOOD_ROOT', __DIR__);
}

if (!defined('FOOD_DB_PATH')) {
    define('FOOD_DB_PATH', getenv('FOOD_DB_PATH') ?: __DIR__ . '/database/food.sqlite');
}

if (!defined('FOOD_UPLOAD_DIR')) {
    define('FOOD_UPLOAD_DIR', getenv('FOOD_UPLOAD_DIR') ?: __DIR__ . '/public/uploads');
}
