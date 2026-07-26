<?php

/**
 * Laravel 13 requires DevCommands to be registered in application code, not
 * vendor packages. Remove Horizon's vendor-side registration after install.
 */
$file = dirname(__DIR__).'/vendor/laravel/horizon/src/HorizonServiceProvider.php';

if (! is_file($file)) {
    exit(0);
}

$content = file_get_contents($file);
$search = "        Horizon::registerDevCommands();\n";
$replace = "        // DevCommands registered in App\\Providers\\AppServiceProvider (Laravel 13).\n";

if (str_contains($content, $search)) {
    file_put_contents($file, str_replace($search, $replace, $content));
}
