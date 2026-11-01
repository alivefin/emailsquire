<?php

// The example module's classes, as a module's own Composer autoloader would load them.
spl_autoload_register(function (string $class): void {
    $prefix = 'EmailSquire\\Modules\\Example\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
